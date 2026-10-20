<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Tests\Fixtures\FixtureHelper;
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

        // The pixel endpoint is public — log out the admin session
        $this->logoutUser();
    }

    /** Confluence Test Case 1 */
    public function testPubliclyUpdatableFieldIsWrittenOnIdentification(): void
    {
        $this->fixtureHelper->makeFieldPubliclyUpdatable('firstname');
        $contact = $this->fixtureHelper->createContact('a@a.com', 'aaa');

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'a@a.com', 'firstname' => 'bbb']);

        self::assertResponseIsSuccessful();

        $mtcCookie = $this->getMtcIdCookieFromResponse();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Cookie::class, $mtcCookie);
        $this->assertSame((string) $contact->getId(), $mtcCookie->getValue());
        $this->em->clear();
        $this->assertSame('bbb', $this->em->getRepository(Lead::class)->find($contact->getId())->getFirstname());
    }

    /** Confluence Test Case 2 */
    public function testInitialIdentificationCreatesNewLead(): void
    {
        $countBefore = $this->em->getRepository(Lead::class)->count([]);

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'newlead@example.com']);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $this->assertSame($countBefore + 1, $this->em->getRepository(Lead::class)->count([]));
        $newLead = $this->em->getRepository(Lead::class)->findOneBy(['email' => 'newlead@example.com']);
        $this->assertInstanceOf(Lead::class, $newLead);
        $this->assertSame((string) $newLead->getId(), $this->getMtcIdCookieFromResponse()->getValue());
    }

    /** Confluence Test Case 3 */
    public function testFieldsAreUpdatedWhenCookieLeadMatchesMauticLead(): void
    {
        $this->fixtureHelper->makeFieldPubliclyUpdatable('firstname');
        $contact = $this->fixtureHelper->createContact('a@a.com', 'aaa');
        $this->client->getCookieJar()->set(new Cookie('mtc_id', (string) $contact->getId()));
        $countBefore = $this->em->getRepository(Lead::class)->count([]);

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'a@a.com', 'firstname' => 'bbb']);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $this->assertSame($countBefore, $this->em->getRepository(Lead::class)->count([]));
        $this->assertSame('bbb', $this->em->getRepository(Lead::class)->find($contact->getId())->getFirstname());
    }

    /** Confluence Test Case 4 */
    public function testCookieIsExchangedWhenLeadMismatches(): void
    {
        $leadA = $this->fixtureHelper->createContact('alice@example.com');
        $leadB = $this->fixtureHelper->createContact('bob@example.com');
        $this->client->getCookieJar()->set(new Cookie('mtc_id', (string) $leadA->getId()));

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'bob@example.com']);

        self::assertResponseIsSuccessful();

        $mtcCookie = $this->getMtcIdCookieFromResponse();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Cookie::class, $mtcCookie);
        $this->assertSame((string) $leadB->getId(), $mtcCookie->getValue());
    }

    /** Confluence Test Case 5 */
    public function testPluginDisabledCreatesAnonymousLead(): void
    {
        $this->fixtureHelper->disablePlugin();
        $identifiedContact = $this->fixtureHelper->createContact('ignored@example.com');
        $countBefore       = $this->em->getRepository(Lead::class)->count([]);

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'ignored@example.com']);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $this->assertSame($countBefore + 1, $this->em->getRepository(Lead::class)->count([]));
        $mtcCookie = $this->getMtcIdCookieFromResponse();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Cookie::class, $mtcCookie);
        $this->assertNotSame((string) $identifiedContact->getId(), $mtcCookie->getValue());
    }

    /** Confluence Test Case 6 */
    public function testAnonymousLeadCreatedWhenPrimaryFieldIsNotPubliclyUpdatable(): void
    {
        $this->fixtureHelper->makeFieldNotPubliclyUpdatable('email');
        $contact     = $this->fixtureHelper->createContact('a@a.com', 'aaa');
        $countBefore = $this->em->getRepository(Lead::class)->count([]);

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'a@a.com']);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $this->assertSame($countBefore + 1, $this->em->getRepository(Lead::class)->count([]));
        $mtcCookie = $this->getMtcIdCookieFromResponse();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Cookie::class, $mtcCookie);
        $this->assertNotSame((string) $contact->getId(), $mtcCookie->getValue());
    }

    /** Confluence Test Case 7 */
    public function testSecondaryParameterMismatchInNoCookiePathCreatesAnonymousLead(): void
    {
        $this->fixtureHelper->updatePluginFeatureSettings([
            'parameter_primary'   => 'email',
            'parameter_secondary' => 'firstname',
        ]);

        $contact     = $this->fixtureHelper->createContact('a@a.com', 'aaa');
        $countBefore = $this->em->getRepository(Lead::class)->count([]);

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'a@a.com', 'firstname' => 'bbb']);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $this->assertSame($countBefore + 1, $this->em->getRepository(Lead::class)->count([]));
        $mtcCookie = $this->getMtcIdCookieFromResponse();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Cookie::class, $mtcCookie);
        $this->assertNotSame((string) $contact->getId(), $mtcCookie->getValue());
        $this->assertSame('aaa', $this->em->getRepository(Lead::class)->find($contact->getId())->getFirstname());
    }

    /** Confluence Test Case 8 */
    public function testSecondaryParameterMismatchDoesNothing(): void
    {
        $this->fixtureHelper->updatePluginFeatureSettings([
            'parameter_primary'   => 'email',
            'parameter_secondary' => 'firstname',
        ]);

        $contact = $this->fixtureHelper->createContact('secure@example.com', 'John');
        $this->client->getCookieJar()->set(new Cookie('mtc_id', (string) $contact->getId()));
        $countBefore = $this->em->getRepository(Lead::class)->count([]);

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'secure@example.com', 'firstname' => 'Jane']);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $this->assertSame($countBefore, $this->em->getRepository(Lead::class)->count([]));
        $this->assertNotInstanceOf(\Symfony\Component\HttpFoundation\Cookie::class, $this->getMtcIdCookieFromResponse());
        $this->assertSame('John', $this->em->getRepository(Lead::class)->find($contact->getId())->getFirstname());
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
