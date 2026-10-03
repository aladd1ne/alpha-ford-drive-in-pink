<?php

namespace App\Form;

use App\Entity\Booking;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class BookingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('customerName', TextType::class, [
                'label' => 'Nom complet',
                'attr' => [
                    'placeholder' => 'Votre nom et prénom',
                    'class' => 'form-input',
                    'autocomplete' => 'name',
                ],
            ])
            ->add('phone', TextType::class, [
                'label' => 'Téléphone',
                'attr' => [
                    'placeholder' => '+216 XX XXX XXX',
                    'class' => 'form-input',
                    'autocomplete' => 'tel',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'attr' => [
                    'placeholder' => 'votre@email.com',
                    'class' => 'form-input',
                    'autocomplete' => 'email',
                ],
            ])
            ->add('peopleCount', IntegerType::class, [
                'label' => 'Nombre de personnes',
                'attr' => [
                    'min' => 1,
                    'max' => 50,
                    'class' => 'form-input',
                ],
                'data' => 1,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(min: 1, max: 50),
                ],
            ])
            ->add('preferredDate', DateType::class, [
                'label' => 'Date souhaitée',
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-input',
                    'min' => (new \DateTime())->format('Y-m-d'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Booking::class,
        ]);
    }
}
