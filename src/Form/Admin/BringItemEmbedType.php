<?php

namespace App\Form\Admin;

use App\Entity\BringItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BringItemEmbedType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('icon', TextType::class, ['label' => 'Icône', 'attr' => ['placeholder' => 'bag / droplet / shirt / footprints / sun']])
            ->add('label', TextType::class, ['label' => 'Libellé', 'attr' => ['placeholder' => 'Maillot de bain']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BringItem::class]);
    }
}
