/** Public booking site dictionary (FR default, EN). Switch with ?lang=. */
export type Lang = 'fr' | 'en';

const dict = {
    fr: {
        book: 'Réserver', manage: 'Gérer ma réservation', ourHotels: 'Nos hôtels', stars: 'étoiles',
        checkIn: 'Arrivée', checkOut: 'Départ', adults: 'Adultes', children: 'Enfants', search: 'Rechercher',
        memberNumber: 'N° de membre (facultatif)', lastName: 'Nom de famille', from: 'à partir de',
        noOffers: 'Aucune chambre disponible pour ces dates.', nights: 'nuit(s)', roomsLeft: 'chambre(s) restante(s)',
        select: 'Choisir', refundable: 'Remboursable', nonRefundable: 'Non remboursable', membersOnly: 'Tarif membre',
        deposit: 'Acompte', depositRequired: 'Acompte requis', mealPlan: 'Formule', yourDetails: 'Vos coordonnées',
        firstName: 'Prénom', email: 'E-mail', phone: 'Téléphone', country: 'Pays (code ISO, ex. CM)',
        requests: 'Demandes particulières', acceptTerms: "J'accepte les conditions de réservation et la politique d'annulation.",
        marketing: "J'accepte de recevoir des offres par e-mail ou SMS.", confirmBooking: 'Confirmer la réservation',
        total: 'Total', confirmed: 'Votre réservation est enregistrée', confirmationNo: 'N° de confirmation',
        keepNumber: 'Conservez ce numéro pour gérer votre réservation.', lookup: 'Retrouver ma réservation',
        status: 'Statut', cancel: 'Annuler la réservation', cancelConfirm: "Confirmer l'annulation",
        keep: 'Garder ma réservation', policy: "Politique d'annulation",
        freeUntil: (h: number) => `Annulation gratuite jusqu'à ${h} h avant l'arrivée ; ensuite des frais peuvent s'appliquer.`,
        nonRefundablePolicy: "Tarif non remboursable : l'annulation entraîne des frais.",
        cancelled: 'Réservation annulée.', fee: "Frais d'annulation", onlineCheckin: 'Enregistrement en ligne',
        idType: "Pièce d'identité", idNumber: 'Numéro de la pièce', nationality: 'Nationalité (code ISO)', eta: "Heure d'arrivée prévue",
        notes: 'Remarques', submit: 'Envoyer', checkinDone: 'Enregistrement en ligne reçu.', payDeposit: "Payer l'acompte",
        method: 'Moyen de paiement', mtn: 'MTN Mobile Money', orange: 'Orange Money', card: 'Carte bancaire',
        payNow: 'Payer', depositDue: 'Acompte dû', depositPending: 'Demande de paiement enregistrée. Référence',
        loyaltyCard: 'Carte de fidélité', points: 'points', notFound: 'Réservation introuvable. Vérifiez le numéro et le nom.',
        passport: 'Passeport', national_id: "Carte nationale d'identité", residence_permit: 'Titre de séjour', driving_licence: 'Permis de conduire',
        language: 'Langue', back: 'Retour', error: 'Une erreur est survenue.', loading: 'Chargement…', arrivalTime: 'Arrivée à partir de',
        statuses: { tentative: 'En attente', confirmed: 'Confirmée', cancelled: 'Annulée', checked_in: 'En séjour', checked_out: 'Terminée', no_show: 'Non présenté' } as Record<string, string>,
    },
    en: {
        book: 'Book', manage: 'Manage my booking', ourHotels: 'Our hotels', stars: 'stars',
        checkIn: 'Check-in', checkOut: 'Check-out', adults: 'Adults', children: 'Children', search: 'Search',
        memberNumber: 'Member number (optional)', lastName: 'Last name', from: 'from',
        noOffers: 'No rooms available for these dates.', nights: 'night(s)', roomsLeft: 'room(s) left',
        select: 'Select', refundable: 'Refundable', nonRefundable: 'Non-refundable', membersOnly: 'Member rate',
        deposit: 'Deposit', depositRequired: 'Deposit required', mealPlan: 'Meal plan', yourDetails: 'Your details',
        firstName: 'First name', email: 'Email', phone: 'Phone', country: 'Country (ISO code, e.g. CM)',
        requests: 'Special requests', acceptTerms: 'I accept the booking terms and the cancellation policy.',
        marketing: 'I agree to receive offers by email or SMS.', confirmBooking: 'Confirm booking',
        total: 'Total', confirmed: 'Your booking is recorded', confirmationNo: 'Confirmation number',
        keepNumber: 'Keep this number to manage your booking.', lookup: 'Find my booking',
        status: 'Status', cancel: 'Cancel booking', cancelConfirm: 'Confirm cancellation',
        keep: 'Keep my booking', policy: 'Cancellation policy',
        freeUntil: (h: number) => `Free cancellation up to ${h} hours before arrival; after that a fee may apply.`,
        nonRefundablePolicy: 'Non-refundable rate: cancelling incurs a fee.',
        cancelled: 'Booking cancelled.', fee: 'Cancellation fee', onlineCheckin: 'Online check-in',
        idType: 'ID document', idNumber: 'Document number', nationality: 'Nationality (ISO code)', eta: 'Expected arrival time',
        notes: 'Notes', submit: 'Submit', checkinDone: 'Online check-in received.', payDeposit: 'Pay deposit',
        method: 'Payment method', mtn: 'MTN Mobile Money', orange: 'Orange Money', card: 'Bank card',
        payNow: 'Pay', depositDue: 'Deposit due', depositPending: 'Payment request recorded. Reference',
        loyaltyCard: 'Loyalty card', points: 'points', notFound: 'Booking not found. Check the number and the name.',
        passport: 'Passport', national_id: 'National ID card', residence_permit: 'Residence permit', driving_licence: 'Driving licence',
        language: 'Language', back: 'Back', error: 'Something went wrong.', loading: 'Loading…', arrivalTime: 'Check-in from',
        statuses: { tentative: 'Pending', confirmed: 'Confirmed', cancelled: 'Cancelled', checked_in: 'In house', checked_out: 'Completed', no_show: 'No-show' } as Record<string, string>,
    },
};

export type Dict = (typeof dict)['fr'];

export function t(lang: Lang): Dict {
    return dict[lang] ?? dict.fr;
}

export function money(amount: number, currency = 'XAF', lang: Lang = 'fr'): string {
    return `${new Intl.NumberFormat(lang === 'en' ? 'en-US' : 'fr-FR').format(amount)} ${currency === 'XAF' ? 'FCFA' : currency}`;
}
