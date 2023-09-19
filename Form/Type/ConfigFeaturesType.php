<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Form\Type;

use Doctrine\DBAL\Exception;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Utility\DataProviderUtility;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

class ConfigFeaturesType extends AbstractType
{
    protected DataProviderUtility $dataProviderUtility;
    protected FieldModel $fieldModel;

    public function __construct(DataProviderUtility $dataProviderUtility, FieldModel $fieldModel)
    {
        $this->dataProviderUtility = $dataProviderUtility;
        $this->fieldModel = $fieldModel;
    }

    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     * @return void
     * @throws Exception
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $uniqueIdentifierFieldNames = $this->dataProviderUtility->getUniqueIdentifierFieldNames();
        $parameterPrimaryChoices = [];
        foreach ($uniqueIdentifierFieldNames as $fieldName) {
            $parameterPrimaryChoices[$fieldName] = $fieldName;
        }

        $builder->add(
            'parameter_primary',
            ChoiceType::class,
            [
                'choices' => $parameterPrimaryChoices,
                'label' => 'leuchtfeueridentitysync.config.parameter_primary',
                'label_attr' => ['class' => 'control-label'],
                'required' => true,
                'multiple' => false,
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'leuchtfeueridentitysync.config.parameter_primary.tooltip',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'mautic.core.value.required']),
                ],
            ],
        );

        $parameterSecondaryChoices = [];
        $leadFields = $this->fieldModel->getRepository()->getFieldAliases('lead');
        foreach ($leadFields as $leadField) {
            $parameterSecondaryChoices[$leadField['alias']] = $leadField['alias'];
        }

        $builder->add(
            'parameter_secondary',
            ChoiceType::class,
            [
                'choices' => $parameterSecondaryChoices,
                'label' => 'leuchtfeueridentitysync.config.parameter_secondary',
                'label_attr' => ['class' => 'control-label'],
                'required' => false,
                'multiple' => false,
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'leuchtfeueridentitysync.config.parameter_secondary.tooltip',
                ],
            ],
        );
    }
}
