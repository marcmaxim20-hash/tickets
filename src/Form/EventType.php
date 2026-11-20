<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Event;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class EventType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Event Name',
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'e.g., Midnight Echoes Live Concert',
                    'maxlength' => 180,
                ],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Event Category',
                'choices' => array_flip(Event::getAvailableCategories()),
            ])
            ->add('venue', TextType::class, [
                'label' => 'Place / Venue',
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'e.g., Valencia Open-Air Arena',
                    'maxlength' => 180,
                ],
            ])
            ->add('location', TextType::class, [
                'label' => 'Event Location / Street Address (Where to Go)',
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'e.g., Passeig de Neptú 2, 46011 Valencia, Spain',
                    'maxlength' => 255,
                ],
            ])
            ->add('totalSeats', IntegerType::class, [
                'label' => 'Total Seats in Venue',
                'attr' => [
                    'min' => 1,
                    'max' => 100000,
                    'placeholder' => 'e.g., 250',
                ],
            ])
            ->add('seatMapEnabled', CheckboxType::class, [
                'label' => 'Enable Interactive Seat Map (let buyers pick exact seats like on an airplane / theatre)',
                'required' => false,
            ])
            ->add('seatMapLayout', ChoiceType::class, [
                'label' => 'Seat Map Layout',
                'choices' => [
                    'Theatre / Auditorium (2 Aisles)' => Event::LAYOUT_THEATRE,
                    'Saloon / Hall (Airplane Center Aisle)' => Event::LAYOUT_SALOON,
                    'Indoor Building / Arena Hall' => Event::LAYOUT_BUILDING,
                    'Festival / Seated Arena (Wide Aisles)' => Event::LAYOUT_FESTIVAL,
                ],
            ])
            ->add('seatRows', IntegerType::class, [
                'label' => 'Number of Rows (A–Z)',
                'attr' => [
                    'min' => 1,
                    'max' => 26,
                    'placeholder' => 'e.g., 8',
                ],
            ])
            ->add('seatsPerRow', IntegerType::class, [
                'label' => 'Seats per Row',
                'attr' => [
                    'min' => 2,
                    'max' => 20,
                    'placeholder' => 'e.g., 10',
                ],
            ])
            ->add('seatMapConfigJson', HiddenType::class, [
                'mapped' => false,
                'required' => false,
            ])
            ->add('startDate', DateTimeType::class, [
                'label' => 'Start Date & Time',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('endDate', DateTimeType::class, [
                'label' => 'End Date & Time',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('imageFile', FileType::class, [
                'label' => 'Upload Festival / Event Photo (JPG, PNG, WEBP, AVIF, GIF, SVG)',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'accept' => 'image/jpeg,image/png,image/webp,image/avif,image/gif,image/svg+xml',
                ],
                'constraints' => [
                    new File(
                        maxSize: '16M',
                        mimeTypes: [
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                            'image/avif',
                            'image/gif',
                            'image/svg+xml',
                        ],
                        mimeTypesMessage: 'Please upload a valid image file (JPG, PNG, WEBP, AVIF, GIF, or SVG).'
                    ),
                ],
            ])
            ->add('imageUrl', TextType::class, [
                'label' => 'Or Paste Photo URL (optional)',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'placeholder' => 'https://example.com/festival-photo.jpg',
                    'maxlength' => 255,
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Event Description',
                'empty_data' => '',
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'Describe performers, lineup, schedule, and venue highlights...',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Event::class,
        ]);
    }
}
