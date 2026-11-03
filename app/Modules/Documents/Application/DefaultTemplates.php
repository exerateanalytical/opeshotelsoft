<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Operations\HrTemplates;

/** Built-in texts used until a hotel customises a template (and after "reset to default"). */
final class DefaultTemplates
{
    public const KINDS = ['invoice', 'quote', 'registration_card', 'confirmation', 'letter', ...HrTemplates::KINDS];

    public const LOCALES = ['fr', 'en'];

    /** @return array{title: string, body: string} */
    public static function get(string $kind, string $locale): array
    {
        if (HrTemplates::is($kind)) {
            return HrTemplates::get($kind, $locale);
        }

        return self::ALL[$kind][$locale] ?? self::ALL[$kind]['fr'];
    }

    private const ALL = [
        'invoice' => [
            'fr' => ['title' => 'Mentions de facture', 'body' => "Conditions de paiement : à réception de facture.\nMerci de votre confiance, {{ hotel.name }}."],
            'en' => ['title' => 'Invoice notes', 'body' => "Payment terms: due on receipt.\nThank you for your business, {{ hotel.name }}."],
        ],
        'quote' => [
            'fr' => ['title' => 'Devis', 'body' => "# Devis {{ reservation.number }}\nMadame, Monsieur {{ guest.name }},\n\nNous avons le plaisir de vous proposer un séjour du {{ reservation.arrival }} au {{ reservation.departure }} ({{ reservation.nights }} nuit(s)) en chambre {{ reservation.room_type }}.\n\nMontant total : **{{ reservation.total }} {{ reservation.currency }}**.\n\nCe devis est valable 15 jours.\n\n{{ hotel.name }}"],
            'en' => ['title' => 'Quotation', 'body' => "# Quotation {{ reservation.number }}\nDear {{ guest.name }},\n\nWe are pleased to offer you a stay from {{ reservation.arrival }} to {{ reservation.departure }} ({{ reservation.nights }} night(s)) in a {{ reservation.room_type }} room.\n\nTotal amount: **{{ reservation.total }} {{ reservation.currency }}**.\n\nThis quotation is valid for 15 days.\n\n{{ hotel.name }}"],
        ],
        'registration_card' => [
            'fr' => ['title' => 'Conditions de séjour', 'body' => "Les chambres sont libérées à {{ hotel.check_out_time }}. L'hôtel décline toute responsabilité pour les objets de valeur non déposés au coffre."],
            'en' => ['title' => 'Terms of stay', 'body' => 'Rooms must be vacated by {{ hotel.check_out_time }}. The hotel is not responsible for valuables not placed in the safe.'],
        ],
        'confirmation' => [
            'fr' => ['title' => 'Confirmation de réservation', 'body' => "# Confirmation de réservation {{ reservation.number }}\nBonjour {{ guest.name }},\n\nNous confirmons votre réservation du {{ reservation.arrival }} au {{ reservation.departure }} ({{ reservation.nights }} nuit(s)), chambre {{ reservation.room_type }}, {{ reservation.adults }} adulte(s).\n\nArrivée à partir de {{ hotel.check_in_time }}. Montant : **{{ reservation.total }} {{ reservation.currency }}**.\n\nAu plaisir de vous accueillir,\n{{ hotel.name }} — {{ hotel.phone }}"],
            'en' => ['title' => 'Booking confirmation', 'body' => "# Booking confirmation {{ reservation.number }}\nHello {{ guest.name }},\n\nWe confirm your booking from {{ reservation.arrival }} to {{ reservation.departure }} ({{ reservation.nights }} night(s)), {{ reservation.room_type }} room, {{ reservation.adults }} adult(s).\n\nCheck-in from {{ hotel.check_in_time }}. Amount: **{{ reservation.total }} {{ reservation.currency }}**.\n\nWe look forward to welcoming you,\n{{ hotel.name }} — {{ hotel.phone }}"],
        ],
        'letter' => [
            'fr' => ['title' => 'Courrier', 'body' => "{{ hotel.city }}, le {{ today }}\n\n{{ guest.name }}\n\nMadame, Monsieur,\n\n…\n\nVeuillez agréer nos salutations distinguées.\n\nLa Direction, {{ hotel.name }}"],
            'en' => ['title' => 'Letter', 'body' => "{{ hotel.city }}, {{ today }}\n\n{{ guest.name }}\n\nDear Sir or Madam,\n\n…\n\nYours faithfully,\n\nThe Management, {{ hotel.name }}"],
        ],
    ];
}
