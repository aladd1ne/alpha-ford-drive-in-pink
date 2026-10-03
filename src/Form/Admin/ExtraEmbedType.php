<?php

namespace App\Form\Admin;

use App\Entity\Extra;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ExtraEmbedType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom', 'attr' => ['placeholder' => 'ex. Lunch box']])
            ->add('price', NumberType::class, ['label' => 'Prix (TND)', 'scale' => 2, 'attr' => ['placeholder' => '0.00']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Extra::class]);
    }
}
