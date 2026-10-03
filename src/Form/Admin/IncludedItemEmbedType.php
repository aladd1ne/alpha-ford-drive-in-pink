<?php

namespace App\Form\Admin;

use App\Entity\IncludedItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class IncludedItemEmbedType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('icon', TextType::class, ['label' => 'Icône', 'attr' => ['placeholder' => 'shield / car / package / utensils / users']])
            ->add('label', TextType::class, ['label' => 'Libellé', 'attr' => ['placeholder' => 'Guide certifié inclus']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => IncludedItem::class]);
    }
}
