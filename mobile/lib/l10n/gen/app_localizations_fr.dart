// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for French (`fr`).
class AppLocalizationsFr extends AppLocalizations {
  AppLocalizationsFr([String locale = 'fr']) : super(locale);

  @override
  String get appTitle => 'OpesHotel';

  @override
  String get settings => 'Paramètres';

  @override
  String get serverUrl => 'Adresse du serveur';

  @override
  String get serverUrlHint => 'ex. http://192.168.1.189:8000';

  @override
  String get language => 'Langue';

  @override
  String get save => 'Enregistrer';

  @override
  String get saved => 'Enregistré';

  @override
  String get guest => 'Client';

  @override
  String get staff => 'Personnel';

  @override
  String get chooseMode => 'Comment voulez-vous utiliser l\'application ?';

  @override
  String get guestModeDesc => 'Réserver, voir mes réservations, carte fidélité';

  @override
  String get staffModeDesc => 'Tâches, interventions, paie, congés, pointage';

  @override
  String get groupSlug => 'Code du groupe hôtelier';

  @override
  String get continueLabel => 'Continuer';

  @override
  String get book => 'Réserver';

  @override
  String get myBookings => 'Mes réservations';

  @override
  String get loyaltyCard => 'Carte fidélité';

  @override
  String get arrival => 'Arrivée';

  @override
  String get departure => 'Départ';

  @override
  String get adults => 'Adultes';

  @override
  String get children => 'Enfants';

  @override
  String get search => 'Rechercher';

  @override
  String get noOffers => 'Aucune chambre disponible pour ces dates.';

  @override
  String nights(int count) {
    return '$count nuit(s)';
  }

  @override
  String roomsLeft(int count) {
    return '$count restante(s)';
  }

  @override
  String get refundable => 'Remboursable';

  @override
  String get nonRefundable => 'Non remboursable';

  @override
  String get firstName => 'Prénom';

  @override
  String get lastName => 'Nom';

  @override
  String get email => 'E-mail';

  @override
  String get phone => 'Téléphone';

  @override
  String get specialRequests => 'Demandes particulières';

  @override
  String get acceptTerms => 'J\'accepte les conditions de réservation';

  @override
  String get confirmBooking => 'Confirmer la réservation';

  @override
  String bookingConfirmed(String number) {
    return 'Réservation confirmée : $number';
  }

  @override
  String get confirmationNumber => 'Numéro de confirmation';

  @override
  String get findBooking => 'Retrouver une réservation';

  @override
  String get noBookings => 'Aucune réservation enregistrée.';

  @override
  String get remove => 'Retirer';

  @override
  String get memberNumber => 'Numéro de membre';

  @override
  String get showCard => 'Afficher la carte';

  @override
  String get cardHelp => 'Présentez ce code à la réception.';

  @override
  String get password => 'Mot de passe';

  @override
  String get mfaCode => 'Code d\'authentification (si demandé)';

  @override
  String get signIn => 'Se connecter';

  @override
  String get signOut => 'Se déconnecter';

  @override
  String get chooseHotel => 'Choisir l\'hôtel';

  @override
  String get housekeeping => 'Ménage';

  @override
  String get roomStatus => 'Statut des chambres';

  @override
  String get workOrders => 'Interventions';

  @override
  String get payslips => 'Mes bulletins';

  @override
  String get leave => 'Congé';

  @override
  String get clock => 'Pointage';

  @override
  String get start => 'Commencer';

  @override
  String get finish => 'Terminer';

  @override
  String get complete => 'Clôturer';

  @override
  String get resolution => 'Travaux effectués';

  @override
  String get noTasks => 'Aucune tâche pour vous aujourd\'hui.';

  @override
  String get noWorkOrders => 'Aucune intervention assignée.';

  @override
  String get noPayslips => 'Aucun bulletin disponible.';

  @override
  String roomNumber(String number) {
    return 'Chambre $number';
  }

  @override
  String get roomId => 'Identifiant de la chambre';

  @override
  String get newStatus => 'Nouveau statut';

  @override
  String get reason => 'Motif';

  @override
  String get apply => 'Appliquer';

  @override
  String get leaveType => 'Type de congé';

  @override
  String get startDate => 'Date de début';

  @override
  String get endDate => 'Date de fin';

  @override
  String get submit => 'Envoyer';

  @override
  String get clockIn => 'Pointer l\'arrivée';

  @override
  String get clockOut => 'Pointer le départ';

  @override
  String clockedInSince(String time) {
    return 'Présent depuis $time';
  }

  @override
  String get notClockedIn => 'Non pointé';

  @override
  String get gross => 'Brut';

  @override
  String get net => 'Net';

  @override
  String pendingSync(int count) {
    return '$count action(s) en attente de synchronisation';
  }

  @override
  String get syncNow => 'Synchroniser';

  @override
  String get done => 'Fait';

  @override
  String get queuedOffline =>
      'Hors ligne : action enregistrée, elle sera envoyée plus tard.';

  @override
  String error(String message) {
    return 'Erreur : $message';
  }

  @override
  String get retry => 'Réessayer';

  @override
  String get required => 'Obligatoire';

  @override
  String get cancel => 'Annuler';
}
