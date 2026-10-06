<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Back-office: an amount added by hand to the cagnotte (donation, cash collected on site...).
 */
final class ManualContributionType extends AbstractType
{
    public const MAX_AMOUNT = 100000;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('amount', IntegerType::class, [
                'label' => 'Montant (DT)',
                'attr' => ['min' => 1, 'max' => self::MAX_AMOUNT, 'step' => 1],
                'constraints' => [
                    new Assert\NotBlank(message: 'Veuillez saisir un montant.'),
                    new Assert\Range(min: 1, max: self::MAX_AMOUNT, notInRangeMessage: 'Le montant doit être compris entre {{ min }} et {{ max }} DT.'),
                ],
            ])
            ->add('note', TextType::class, [
                'label' => 'Origine du montant',
                'attr' => ['maxlength' => 255, 'placeholder' => 'Ex. : don sur place, collecte du stand…'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Veuillez préciser l’origine du montant.'),
                    new Assert\Length(max: 255),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_token_id' => 'manual-contribution',
        ]);
    }
}
