<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Ticket;
use App\Service\TicketCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TicketType extends AbstractType
{
    public function __construct(
        private readonly TicketCatalog $ticketCatalog,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tierKey', ChoiceType::class, [
                'label' => 'Choose Your Ticket',
                'choices' => $this->ticketCatalog->getFormChoices(),
                'expanded' => true,
                'multiple' => false,
                'empty_data' => TicketCatalog::DEFAULT_TIER,
            ])
            ->add('contact', EmailType::class, [
                'label' => 'Email',
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'you@gmail.com',
                    'maxlength' => 180,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ticket::class,
        ]);
    }
}
