<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Tests\Fixtures\FixtureHelper;
use PHPUnit\Framework\Assert;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;

final class PublicControllerFunctionalTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    private FixtureHelper $fixtureHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureHelper = new FixtureHelper($this->em, $this->client);
        $this->fixtureHelper->createAndEnablePlugin('email');
        $this->fixtureHelper->makeFieldPubliclyUpdatable('email');

        // The pixel endpoint is public — log out the admin session so ContactTracker
        // works normally (an active Mautic login bypasses cookie tracking).
        $this->logoutUser();
    }

    /**
     * Case 1: No cookie present, lead already exists in Mautic.
     */
    public function testInitialIdentificationWithExistingLead(): void
    {
        $contact = $this->fixtureHelper->createContact('initial@example.com');

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'initial@example.com']);

        self::assertResponseIsSuccessful();

        $mtcCookie = $this->getMtcIdCookieFromResponse();
        Assert::assertNotNull($mtcCookie, 'Response must set an mtc_id cookie.');
        Assert::assertSame((string) $contact->getId(), $mtcCookie->getValue());
    }

    /**
     * Case 2: No cookie present, no matching lead in Mautic.
     */
    public function testInitialIdentificationCreatesNewLead(): void
    {
        $countBefore = $this->em->getRepository(Lead::class)->count([]);

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'newlead@example.com']);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        Assert::assertSame($countBefore + 1, $this->em->getRepository(Lead::class)->count([]));

        $newLead = $this->em->getRepository(Lead::class)->findOneBy(['email' => 'newlead@example.com']);
        Assert::assertNotNull($newLead);

        $mtcCookie = $this->getMtcIdCookieFromResponse();
        Assert::assertNotNull($mtcCookie, 'Response must set an mtc_id cookie.');
        Assert::assertSame((string) $newLead->getId(), $mtcCookie->getValue());
    }

    /**
     * Case 3: Cookie present, email in cookie-lead matches email in query.
     */
    public function testFieldsAreUpdatedWhenCookieLeadMatchesMauticLead(): void
    {
        $contact = $this->fixtureHelper->createContact('returning@example.com');
        $this->client->getCookieJar()->set(new Cookie('mtc_id', (string) $contact->getId()));

        $countBefore = $this->em->getRepository(Lead::class)->count([]);

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'returning@example.com']);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        Assert::assertSame($countBefore, $this->em->getRepository(Lead::class)->count([]), 'No new lead must be created for a recognised contact.');
    }

    /**
     * Case 4: Cookie present, but cookie-lead email differs from query email, and another lead
     * matching the query email already exists.
     */
    public function testCookieIsExchangedWhenLeadMismatches(): void
    {
        $leadA = $this->fixtureHelper->createContact('alice@example.com');
        $leadB = $this->fixtureHelper->createContact('bob@example.com');

        $this->client->getCookieJar()->set(new Cookie('mtc_id', (string) $leadA->getId()));

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'bob@example.com']);

        self::assertResponseIsSuccessful();

        $mtcCookie = $this->getMtcIdCookieFromResponse();
        Assert::assertNotNull($mtcCookie, 'Response must set a new mtc_id cookie.');
        Assert::assertSame((string) $leadB->getId(), $mtcCookie->getValue(), 'Cookie must be switched to lead B.');
    }

    /**
     * Case 5: Plugin is disabled.
     */
    public function testPluginDisabledReturnsPixelWithoutTracking(): void
    {
        $this->fixtureHelper->disablePlugin();
        $countBefore = $this->em->getRepository(Lead::class)->count([]);

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'ignored@example.com']);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        Assert::assertSame($countBefore, $this->em->getRepository(Lead::class)->count([]), 'No lead must be created when plugin is disabled.');
        Assert::assertNull($this->getMtcIdCookieFromResponse(), 'No mtc_id cookie must be set when plugin is disabled.');
    }

    /**
     * Case 6: Secondary parameter is configured, primary matches but secondary does not.
     */
    public function testSecondaryParameterMismatchDoesNothing(): void
    {
        $this->fixtureHelper->updatePluginFeatureSettings([
            'parameter_primary'   => 'email',
            'parameter_secondary' => 'firstname',
        ]);

        $contact = $this->fixtureHelper->createContact('secure@example.com', 'John');
        $this->client->getCookieJar()->set(new Cookie('mtc_id', (string) $contact->getId()));

        $countBefore = $this->em->getRepository(Lead::class)->count([]);

        // Primary matches (same email), but secondary does not (Jane vs John)
        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', [
            'email'     => 'secure@example.com',
            'firstname' => 'Jane',
        ]);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        Assert::assertSame($countBefore, $this->em->getRepository(Lead::class)->count([]), 'No lead must be created on secondary parameter mismatch.');
        Assert::assertNull($this->getMtcIdCookieFromResponse(), 'No cookie change must occur on secondary parameter mismatch.');

        $unchanged = $this->em->getRepository(Lead::class)->find($contact->getId());
        Assert::assertSame('John', $unchanged->getFirstname());
    }

    /**
     * Case 7: Cookie present, email mismatches, and no other lead matches the query email.
     */
    public function testNewLeadCreatedWhenCookieMismatchesAndNoOtherLeadExists(): void
    {
        $leadA = $this->fixtureHelper->createContact('alice@example.com');
        $this->client->getCookieJar()->set(new Cookie('mtc_id', (string) $leadA->getId()));

        $countBefore = $this->em->getRepository(Lead::class)->count([]);

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'unknown@example.com']);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        Assert::assertSame($countBefore + 1, $this->em->getRepository(Lead::class)->count([]), 'A new lead must be created.');

        $newLead = $this->em->getRepository(Lead::class)->findOneBy(['email' => 'unknown@example.com']);
        Assert::assertNotNull($newLead);

        $mtcCookie = $this->getMtcIdCookieFromResponse();
        Assert::assertNotNull($mtcCookie, 'Response must set a new mtc_id cookie.');
        Assert::assertSame((string) $newLead->getId(), $mtcCookie->getValue(), 'Cookie must be switched to the new lead.');
    }

    private function getMtcIdCookieFromResponse(): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ('mtc_id' === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }
}
