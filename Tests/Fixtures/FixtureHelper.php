<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\Plugin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class FixtureHelper
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KernelBrowser $client,
    ) {
    }

    public function createAndEnablePlugin(string $parameterPrimary = 'email'): void
    {
        // plugins/reload registers the Plugin entity in the DB — required for IntegrationsHelper
        $this->client->request('GET', '/s/plugins/reload');

        $plugin = $this->em->getRepository(Plugin::class)->findOneBy(['bundle' => 'LeuchtfeuerIdentitySyncBundle']);

        $integration = $this->em->getRepository(Integration::class)->findOneBy(['name' => 'leuchtfeueridentitysync'])
            ?? new Integration();

        $integration->setPlugin($plugin);
        $integration->setIsPublished(true);
        $integration->setName('leuchtfeueridentitysync');
        $integration->setFeatureSettings(['integration' => ['parameter_primary' => $parameterPrimary]]);
        $this->em->persist($integration);
        $this->em->flush();
    }

    public function disablePlugin(): void
    {
        $integration = $this->em->getRepository(Integration::class)->findOneBy(['name' => 'leuchtfeueridentitysync']);
        $integration->setIsPublished(false);
        $this->em->persist($integration);
        $this->em->flush();
    }

    /**
     * @param array<string, string> $settings e.g. ['parameter_primary' => 'email', 'parameter_secondary' => 'firstname']
     */
    public function updatePluginFeatureSettings(array $settings): void
    {
        $integration = $this->em->getRepository(Integration::class)->findOneBy(['name' => 'leuchtfeueridentitysync']);
        $integration->setFeatureSettings(['integration' => $settings]);
        $this->em->persist($integration);
        $this->em->flush();
    }

    public function makeFieldPubliclyUpdatable(string $alias): void
    {
        $field = $this->em->getRepository(LeadField::class)->findOneBy(['alias' => $alias]);

        if (null === $field) {
            throw new \RuntimeException(sprintf('LeadField with alias "%s" not found.', $alias));
        }

        $field->setIsPubliclyUpdatable(true);
        $this->em->persist($field);
        $this->em->flush();
    }

    public function makeFieldNotPubliclyUpdatable(string $alias): void
    {
        $field = $this->em->getRepository(LeadField::class)->findOneBy(['alias' => $alias]);

        if (null === $field) {
            throw new \RuntimeException(sprintf('LeadField with alias "%s" not found.', $alias));
        }

        $field->setIsPubliclyUpdatable(false);
        $this->em->persist($field);
        $this->em->flush();
    }

    public function setFieldUniqueIdentifier(string $alias, bool $isUnique): void
    {
        $field = $this->em->getRepository(LeadField::class)->findOneBy(['alias' => $alias]);

        if (null === $field) {
            throw new \RuntimeException(sprintf('LeadField with alias "%s" not found.', $alias));
        }

        $field->setIsUniqueIdentifier($isUnique);
        $this->em->persist($field);
        $this->em->flush();
    }

    public function createContact(string $email, ?string $firstname = null, ?string $mobile = null): Lead
    {
        $contact = new Lead();
        $contact->setEmail($email);

        if (null !== $firstname) {
            $contact->setFirstname($firstname);
        }

        if (null !== $mobile) {
            $contact->setMobile($mobile);
        }

        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }
}
