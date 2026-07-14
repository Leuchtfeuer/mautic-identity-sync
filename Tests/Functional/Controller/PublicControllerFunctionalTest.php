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

        // The pixel endpoint is public — log out the admin session
        $this->logoutUser();
    }

    /**
     * TC1: No cookie present, lead already exists in Mautic.
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
     * TC2: No cookie present, no matching lead in Mautic.
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
     * TC3 (new): Email is unique identifier but NOT publicly updatable.
     * Plugin must return the pixel immediately at the hasPubliclyUpdatableUniqueIdentifier check
     * without setting any cookie.
     */
    public function testAnonymousLeadCreatedWhenPrimaryFieldIsNotPubliclyUpdatable(): void
    {
        $this->fixtureHelper->makeFieldNotPubliclyUpdatable('email');
        $contact = $this->fixtureHelper->createContact('a@a.com', 'aaa');

        $countBefore = $this->em->getRepository(Lead::class)->count([]);

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'a@a.com']);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        Assert::assertSame($countBefore + 1, $this->em->getRepository(Lead::class)->count([]), 'An anonymous lead must be created.');

        $mtcCookie = $this->getMtcIdCookieFromResponse();
        Assert::assertNotNull($mtcCookie, 'An mtc_id cookie must be set for anonymous tracking.');
        Assert::assertNotSame((string) $contact->getId(), $mtcCookie->getValue(), 'Cookie must not point to the identified lead.');
    }

    /**
     * TC4 (new): Email is NOT publicly updatable. Mobile (=custid) is unique identifier AND
     * publicly updatable. Both email and mobile are passed and match an existing lead.
     * Cookie must be set to that lead's ID.
     */
    public function testIdentificationByMobileWhenEmailIsNotPubliclyUpdatable(): void
    {
        $this->fixtureHelper->makeFieldNotPubliclyUpdatable('email');
        $this->fixtureHelper->makeFieldPubliclyUpdatable('mobile');
        $this->fixtureHelper->setFieldUniqueIdentifier('mobile', true);
        $this->fixtureHelper->updatePluginFeatureSettings([
            'parameter_primary'   => 'mobile',
            'parameter_secondary' => 'email',
        ]);

        $contact = $this->fixtureHelper->createContact('a@a.com', 'aaa', '123');

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', [
            'email'  => 'a@a.com',
            'mobile' => '123',
        ]);

        self::assertResponseIsSuccessful();

        $mtcCookie = $this->getMtcIdCookieFromResponse();
        Assert::assertNotNull($mtcCookie, 'Response must set an mtc_id cookie.');
        Assert::assertSame((string) $contact->getId(), $mtcCookie->getValue(), 'Cookie must be set to the matching lead.');
    }

    /**
     * TC5 (new): Email primary (publicly updatable, unique identifier). Mobile secondary
     * (publicly updatable, unique identifier). Lead has email=a@a.com, mobile=123. No cookie.
     * Mobile in query does NOT match (1234 vs 123) — an anonymous lead must be created and
     * the identified lead's mobile must NOT be overwritten.
     *
     * @todo Known bug: secondary parameter is not validated in the no-cookie path.
     *       This test asserts the correct expected behavior and will currently fail.
     */
    public function testSecondaryParameterMismatchInNoCookiePathCreatesAnonymousLead(): void
    {
        // Known bug: secondary parameter is not validated in the no-cookie path.
        // This test asserts the correct expected behavior and will currently fail.
        $this->fixtureHelper->makeFieldPubliclyUpdatable('mobile');
        $this->fixtureHelper->setFieldUniqueIdentifier('mobile', true);
        $this->fixtureHelper->updatePluginFeatureSettings([
            'parameter_primary'   => 'email',
            'parameter_secondary' => 'mobile',
        ]);

        $contact = $this->fixtureHelper->createContact('a@a.com', 'aaa', '123');

        $countBefore = $this->em->getRepository(Lead::class)->count([]);

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', [
            'email'  => 'a@a.com',
            'mobile' => '1234',
        ]);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        Assert::assertSame($countBefore + 1, $this->em->getRepository(Lead::class)->count([]), 'An anonymous lead must be created.');

        $mtcCookie = $this->getMtcIdCookieFromResponse();
        Assert::assertNotNull($mtcCookie, 'An mtc_id cookie must be set for anonymous tracking.');
        Assert::assertNotSame((string) $contact->getId(), $mtcCookie->getValue(), 'Cookie must not point to the identified lead.');

        $unchanged = $this->em->getRepository(Lead::class)->find($contact->getId());
        Assert::assertSame('123', $unchanged->getMobile(), 'Mobile must not be overwritten.');
    }

    /**
     * TC6 (new): Mobile (=custid) is the primary parameter. Two leads share the same email
     * but have different mobiles. The correct lead must be identified by mobile.
     */
    public function testPrimaryMobileDistinguishesBetweenLeadsWithSameEmail(): void
    {
        $this->fixtureHelper->makeFieldPubliclyUpdatable('mobile');
        $this->fixtureHelper->setFieldUniqueIdentifier('mobile', true);
        // email must NOT be a unique identifier — otherwise checkForDuplicateContact resolves
        // both leads ambiguously via email and may pick the wrong one.
        $this->fixtureHelper->setFieldUniqueIdentifier('email', false);
        $this->fixtureHelper->updatePluginFeatureSettings(['parameter_primary' => 'mobile']);

        $leadA = $this->fixtureHelper->createContact('a@a.com', null, '1111');
        $leadB = $this->fixtureHelper->createContact('a@a.com', null, '2222');

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', [
            'email'  => 'a@a.com',
            'mobile' => '1111',
        ]);

        self::assertResponseIsSuccessful();

        $mtcCookie = $this->getMtcIdCookieFromResponse();
        Assert::assertNotNull($mtcCookie, 'Response must set an mtc_id cookie.');
        Assert::assertSame((string) $leadA->getId(), $mtcCookie->getValue(), 'Cookie must point to lead A (mobile=1111), not lead B (mobile=2222).');
        Assert::assertNotSame((string) $leadB->getId(), $mtcCookie->getValue());
    }

    /**
     * TC7 (new): No cookie. Lead exists. Publicly updatable field (firstname) is also passed.
     * Cookie must be set and firstname must be updated in the DB.
     */
    public function testPubliclyUpdatableFieldIsWrittenOnIdentification(): void
    {
        $this->fixtureHelper->makeFieldPubliclyUpdatable('firstname');
        $contact = $this->fixtureHelper->createContact('a@a.com', 'aaa');

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', [
            'email'     => 'a@a.com',
            'firstname' => 'bbb',
        ]);

        self::assertResponseIsSuccessful();

        $mtcCookie = $this->getMtcIdCookieFromResponse();
        Assert::assertNotNull($mtcCookie, 'Response must set an mtc_id cookie.');
        Assert::assertSame((string) $contact->getId(), $mtcCookie->getValue());

        $this->em->clear();
        $updated = $this->em->getRepository(Lead::class)->find($contact->getId());
        Assert::assertSame('bbb', $updated->getFirstname(), 'firstname must be updated to the value from the query.');
    }

    /**
     * TC8 (new): Cookie present pointing to a non-existing lead (stale cookie).
     * The request carries an email that matches an existing lead.
     * Cookie must be updated to the existing lead's ID.
     */
    public function testStaleCookieIsTreatedAsNoCookie(): void
    {
        $contact = $this->fixtureHelper->createContact('a@a.com');
        $this->client->getCookieJar()->set(new Cookie('mtc_id', '999999'));

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', ['email' => 'a@a.com']);

        self::assertResponseIsSuccessful();

        $mtcCookie = $this->getMtcIdCookieFromResponse();
        Assert::assertNotNull($mtcCookie, 'Response must set an mtc_id cookie.');
        Assert::assertSame((string) $contact->getId(), $mtcCookie->getValue(), 'Cookie must be set to the existing lead, not the stale ID.');
    }

    /**
     * TC9 (modified): Cookie present, email in cookie-lead matches email in query.
     * No new lead is created and a publicly updatable field (firstname) is written.
     */
    public function testFieldsAreUpdatedWhenCookieLeadMatchesMauticLead(): void
    {
        $this->fixtureHelper->makeFieldPubliclyUpdatable('firstname');
        $contact = $this->fixtureHelper->createContact('returning@example.com', 'aaa');
        $this->client->getCookieJar()->set(new Cookie('mtc_id', (string) $contact->getId()));

        $countBefore = $this->em->getRepository(Lead::class)->count([]);

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', [
            'email'     => 'returning@example.com',
            'firstname' => 'bbb',
        ]);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        Assert::assertSame($countBefore, $this->em->getRepository(Lead::class)->count([]), 'No new lead must be created for a recognised contact.');

        $updated = $this->em->getRepository(Lead::class)->find($contact->getId());
        Assert::assertSame('bbb', $updated->getFirstname(), 'firstname must be updated to the value from the query.');
    }

    /**
     * TC10 (already covered): Cookie present, but cookie-lead email differs from query email,
     * and another lead matching the query email already exists.
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
     * TC11 (modified): Cookie points to existing lead. Email mismatches and no other lead exists
     * for the requested email. A new lead is created with firstname from query, cookie switches.
     */
    public function testNewLeadCreatedWhenCookieMismatchesAndNoOtherLeadExists(): void
    {
        $this->fixtureHelper->makeFieldPubliclyUpdatable('firstname');
        $leadA = $this->fixtureHelper->createContact('alice@example.com');
        $this->client->getCookieJar()->set(new Cookie('mtc_id', (string) $leadA->getId()));

        $countBefore = $this->em->getRepository(Lead::class)->count([]);

        $this->client->request(Request::METHOD_GET, '/mcontrol.gif', [
            'email'     => 'z@z.com',
            'firstname' => 'zzz',
        ]);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        Assert::assertSame($countBefore + 1, $this->em->getRepository(Lead::class)->count([]), 'A new lead must be created.');

        $newLead = $this->em->getRepository(Lead::class)->findOneBy(['email' => 'z@z.com']);
        Assert::assertNotNull($newLead);
        Assert::assertSame('zzz', $newLead->getFirstname(), 'New lead must have firstname from the query.');

        $mtcCookie = $this->getMtcIdCookieFromResponse();
        Assert::assertNotNull($mtcCookie, 'Response must set a new mtc_id cookie.');
        Assert::assertSame((string) $newLead->getId(), $mtcCookie->getValue(), 'Cookie must be switched to the new lead.');
    }

    /**
     * TC12 (already covered): Plugin is disabled — pixel is returned, no tracking occurs.
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
     * TC13 (already covered): Secondary parameter is configured, primary matches but secondary
     * does not (with cookie present). No changes must occur.
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
