<?php

namespace App\Form\Admin;

use App\Entity\Highlight;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class HighlightEmbedType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('icon', TextType::class, ['label' => 'Icône', 'attr' => ['placeholder' => 'clock / map-pin / users / zap']])
            ->add('label', TextType::class, ['label' => 'Étiquette', 'attr' => ['placeholder' => 'Durée']])
            ->add('value', TextType::class, ['label' => 'Valeur', 'attr' => ['placeholder' => '8 heures']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Highlight::class]);
    }
}
