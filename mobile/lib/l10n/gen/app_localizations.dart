import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart' as intl;

import 'app_localizations_en.dart';
import 'app_localizations_fr.dart';

// ignore_for_file: type=lint

/// Callers can lookup localized strings with an instance of AppLocalizations
/// returned by `AppLocalizations.of(context)`.
///
/// Applications need to include `AppLocalizations.delegate()` in their app's
/// `localizationDelegates` list, and the locales they support in the app's
/// `supportedLocales` list. For example:
///
/// ```dart
/// import 'gen/app_localizations.dart';
///
/// return MaterialApp(
///   localizationsDelegates: AppLocalizations.localizationsDelegates,
///   supportedLocales: AppLocalizations.supportedLocales,
///   home: MyApplicationHome(),
/// );
/// ```
///
/// ## Update pubspec.yaml
///
/// Please make sure to update your pubspec.yaml to include the following
/// packages:
///
/// ```yaml
/// dependencies:
///   # Internationalization support.
///   flutter_localizations:
///     sdk: flutter
///   intl: any # Use the pinned version from flutter_localizations
///
///   # Rest of dependencies
/// ```
///
/// ## iOS Applications
///
/// iOS applications define key application metadata, including supported
/// locales, in an Info.plist file that is built into the application bundle.
/// To configure the locales supported by your app, you’ll need to edit this
/// file.
///
/// First, open your project’s ios/Runner.xcworkspace Xcode workspace file.
/// Then, in the Project Navigator, open the Info.plist file under the Runner
/// project’s Runner folder.
///
/// Next, select the Information Property List item, select Add Item from the
/// Editor menu, then select Localizations from the pop-up menu.
///
/// Select and expand the newly-created Localizations item then, for each
/// locale your application supports, add a new item and select the locale
/// you wish to add from the pop-up menu in the Value field. This list should
/// be consistent with the languages listed in the AppLocalizations.supportedLocales
/// property.
abstract class AppLocalizations {
  AppLocalizations(String locale)
    : localeName = intl.Intl.canonicalizedLocale(locale.toString());

  final String localeName;

  static AppLocalizations of(BuildContext context) {
    return Localizations.of<AppLocalizations>(context, AppLocalizations)!;
  }

  static const LocalizationsDelegate<AppLocalizations> delegate =
      _AppLocalizationsDelegate();

  /// A list of this localizations delegate along with the default localizations
  /// delegates.
  ///
  /// Returns a list of localizations delegates containing this delegate along with
  /// GlobalMaterialLocalizations.delegate, GlobalCupertinoLocalizations.delegate,
  /// and GlobalWidgetsLocalizations.delegate.
  ///
  /// Additional delegates can be added by appending to this list in
  /// MaterialApp. This list does not have to be used at all if a custom list
  /// of delegates is preferred or required.
  static const List<LocalizationsDelegate<dynamic>> localizationsDelegates =
      <LocalizationsDelegate<dynamic>>[
        delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ];

  /// A list of this localizations delegate's supported locales.
  static const List<Locale> supportedLocales = <Locale>[
    Locale('en'),
    Locale('fr'),
  ];

  /// No description provided for @appTitle.
  ///
  /// In fr, this message translates to:
  /// **'OpesHotel'**
  String get appTitle;

  /// No description provided for @settings.
  ///
  /// In fr, this message translates to:
  /// **'Paramètres'**
  String get settings;

  /// No description provided for @serverUrl.
  ///
  /// In fr, this message translates to:
  /// **'Adresse du serveur'**
  String get serverUrl;

  /// No description provided for @serverUrlHint.
  ///
  /// In fr, this message translates to:
  /// **'ex. http://192.168.1.189:8000'**
  String get serverUrlHint;

  /// No description provided for @language.
  ///
  /// In fr, this message translates to:
  /// **'Langue'**
  String get language;

  /// No description provided for @save.
  ///
  /// In fr, this message translates to:
  /// **'Enregistrer'**
  String get save;

  /// No description provided for @saved.
  ///
  /// In fr, this message translates to:
  /// **'Enregistré'**
  String get saved;

  /// No description provided for @guest.
  ///
  /// In fr, this message translates to:
  /// **'Client'**
  String get guest;

  /// No description provided for @staff.
  ///
  /// In fr, this message translates to:
  /// **'Personnel'**
  String get staff;

  /// No description provided for @chooseMode.
  ///
  /// In fr, this message translates to:
  /// **'Comment voulez-vous utiliser l\'application ?'**
  String get chooseMode;

  /// No description provided for @guestModeDesc.
  ///
  /// In fr, this message translates to:
  /// **'Réserver, voir mes réservations, carte fidélité'**
  String get guestModeDesc;

  /// No description provided for @staffModeDesc.
  ///
  /// In fr, this message translates to:
  /// **'Tâches, interventions, paie, congés, pointage'**
  String get staffModeDesc;

  /// No description provided for @groupSlug.
  ///
  /// In fr, this message translates to:
  /// **'Code du groupe hôtelier'**
  String get groupSlug;

  /// No description provided for @continueLabel.
  ///
  /// In fr, this message translates to:
  /// **'Continuer'**
  String get continueLabel;

  /// No description provided for @book.
  ///
  /// In fr, this message translates to:
  /// **'Réserver'**
  String get book;

  /// No description provided for @myBookings.
  ///
  /// In fr, this message translates to:
  /// **'Mes réservations'**
  String get myBookings;

  /// No description provided for @loyaltyCard.
  ///
  /// In fr, this message translates to:
  /// **'Carte fidélité'**
  String get loyaltyCard;

  /// No description provided for @arrival.
  ///
  /// In fr, this message translates to:
  /// **'Arrivée'**
  String get arrival;

  /// No description provided for @departure.
  ///
  /// In fr, this message translates to:
  /// **'Départ'**
  String get departure;

  /// No description provided for @adults.
  ///
  /// In fr, this message translates to:
  /// **'Adultes'**
  String get adults;

  /// No description provided for @children.
  ///
  /// In fr, this message translates to:
  /// **'Enfants'**
  String get children;

  /// No description provided for @search.
  ///
  /// In fr, this message translates to:
  /// **'Rechercher'**
  String get search;

  /// No description provided for @noOffers.
  ///
  /// In fr, this message translates to:
  /// **'Aucune chambre disponible pour ces dates.'**
  String get noOffers;

  /// No description provided for @nights.
  ///
  /// In fr, this message translates to:
  /// **'{count} nuit(s)'**
  String nights(int count);

  /// No description provided for @roomsLeft.
  ///
  /// In fr, this message translates to:
  /// **'{count} restante(s)'**
  String roomsLeft(int count);

  /// No description provided for @refundable.
  ///
  /// In fr, this message translates to:
  /// **'Remboursable'**
  String get refundable;

  /// No description provided for @nonRefundable.
  ///
  /// In fr, this message translates to:
  /// **'Non remboursable'**
  String get nonRefundable;

  /// No description provided for @firstName.
  ///
  /// In fr, this message translates to:
  /// **'Prénom'**
  String get firstName;

  /// No description provided for @lastName.
  ///
  /// In fr, this message translates to:
  /// **'Nom'**
  String get lastName;

  /// No description provided for @email.
  ///
  /// In fr, this message translates to:
  /// **'E-mail'**
  String get email;

  /// No description provided for @phone.
  ///
  /// In fr, this message translates to:
  /// **'Téléphone'**
  String get phone;

  /// No description provided for @specialRequests.
  ///
  /// In fr, this message translates to:
  /// **'Demandes particulières'**
  String get specialRequests;

  /// No description provided for @acceptTerms.
  ///
  /// In fr, this message translates to:
  /// **'J\'accepte les conditions de réservation'**
  String get acceptTerms;

  /// No description provided for @confirmBooking.
  ///
  /// In fr, this message translates to:
  /// **'Confirmer la réservation'**
  String get confirmBooking;

  /// No description provided for @bookingConfirmed.
  ///
  /// In fr, this message translates to:
  /// **'Réservation confirmée : {number}'**
  String bookingConfirmed(String number);

  /// No description provided for @confirmationNumber.
  ///
  /// In fr, this message translates to:
  /// **'Numéro de confirmation'**
  String get confirmationNumber;

  /// No description provided for @findBooking.
  ///
  /// In fr, this message translates to:
  /// **'Retrouver une réservation'**
  String get findBooking;

  /// No description provided for @noBookings.
  ///
  /// In fr, this message translates to:
  /// **'Aucune réservation enregistrée.'**
  String get noBookings;

  /// No description provided for @remove.
  ///
  /// In fr, this message translates to:
  /// **'Retirer'**
  String get remove;

  /// No description provided for @memberNumber.
  ///
  /// In fr, this message translates to:
  /// **'Numéro de membre'**
  String get memberNumber;

  /// No description provided for @showCard.
  ///
  /// In fr, this message translates to:
  /// **'Afficher la carte'**
  String get showCard;

  /// No description provided for @cardHelp.
  ///
  /// In fr, this message translates to:
  /// **'Présentez ce code à la réception.'**
  String get cardHelp;

  /// No description provided for @password.
  ///
  /// In fr, this message translates to:
  /// **'Mot de passe'**
  String get password;

  /// No description provided for @mfaCode.
  ///
  /// In fr, this message translates to:
  /// **'Code d\'authentification (si demandé)'**
  String get mfaCode;

  /// No description provided for @signIn.
  ///
  /// In fr, this message translates to:
  /// **'Se connecter'**
  String get signIn;

  /// No description provided for @signOut.
  ///
  /// In fr, this message translates to:
  /// **'Se déconnecter'**
  String get signOut;

  /// No description provided for @chooseHotel.
  ///
  /// In fr, this message translates to:
  /// **'Choisir l\'hôtel'**
  String get chooseHotel;

  /// No description provided for @housekeeping.
  ///
  /// In fr, this message translates to:
  /// **'Ménage'**
  String get housekeeping;

  /// No description provided for @roomStatus.
  ///
  /// In fr, this message translates to:
  /// **'Statut des chambres'**
  String get roomStatus;

  /// No description provided for @workOrders.
  ///
  /// In fr, this message translates to:
  /// **'Interventions'**
  String get workOrders;

  /// No description provided for @payslips.
  ///
  /// In fr, this message translates to:
  /// **'Mes bulletins'**
  String get payslips;

  /// No description provided for @leave.
  ///
  /// In fr, this message translates to:
  /// **'Congé'**
  String get leave;

  /// No description provided for @clock.
  ///
  /// In fr, this message translates to:
  /// **'Pointage'**
  String get clock;

  /// No description provided for @start.
  ///
  /// In fr, this message translates to:
  /// **'Commencer'**
  String get start;

  /// No description provided for @finish.
  ///
  /// In fr, this message translates to:
  /// **'Terminer'**
  String get finish;

  /// No description provided for @complete.
  ///
  /// In fr, this message translates to:
  /// **'Clôturer'**
  String get complete;

  /// No description provided for @resolution.
  ///
  /// In fr, this message translates to:
  /// **'Travaux effectués'**
  String get resolution;

  /// No description provided for @noTasks.
  ///
  /// In fr, this message translates to:
  /// **'Aucune tâche pour vous aujourd\'hui.'**
  String get noTasks;

  /// No description provided for @noWorkOrders.
  ///
  /// In fr, this message translates to:
  /// **'Aucune intervention assignée.'**
  String get noWorkOrders;

  /// No description provided for @noPayslips.
  ///
  /// In fr, this message translates to:
  /// **'Aucun bulletin disponible.'**
  String get noPayslips;

  /// No description provided for @roomNumber.
  ///
  /// In fr, this message translates to:
  /// **'Chambre {number}'**
  String roomNumber(String number);

  /// No description provided for @roomId.
  ///
  /// In fr, this message translates to:
  /// **'Identifiant de la chambre'**
  String get roomId;

  /// No description provided for @newStatus.
  ///
  /// In fr, this message translates to:
  /// **'Nouveau statut'**
  String get newStatus;

  /// No description provided for @reason.
  ///
  /// In fr, this message translates to:
  /// **'Motif'**
  String get reason;

  /// No description provided for @apply.
  ///
  /// In fr, this message translates to:
  /// **'Appliquer'**
  String get apply;

  /// No description provided for @leaveType.
  ///
  /// In fr, this message translates to:
  /// **'Type de congé'**
  String get leaveType;

  /// No description provided for @startDate.
  ///
  /// In fr, this message translates to:
  /// **'Date de début'**
  String get startDate;

  /// No description provided for @endDate.
  ///
  /// In fr, this message translates to:
  /// **'Date de fin'**
  String get endDate;

  /// No description provided for @submit.
  ///
  /// In fr, this message translates to:
  /// **'Envoyer'**
  String get submit;

  /// No description provided for @clockIn.
  ///
  /// In fr, this message translates to:
  /// **'Pointer l\'arrivée'**
  String get clockIn;

  /// No description provided for @clockOut.
  ///
  /// In fr, this message translates to:
  /// **'Pointer le départ'**
  String get clockOut;

  /// No description provided for @clockedInSince.
  ///
  /// In fr, this message translates to:
  /// **'Présent depuis {time}'**
  String clockedInSince(String time);

  /// No description provided for @notClockedIn.
  ///
  /// In fr, this message translates to:
  /// **'Non pointé'**
  String get notClockedIn;

  /// No description provided for @gross.
  ///
  /// In fr, this message translates to:
  /// **'Brut'**
  String get gross;

  /// No description provided for @net.
  ///
  /// In fr, this message translates to:
  /// **'Net'**
  String get net;

  /// No description provided for @pendingSync.
  ///
  /// In fr, this message translates to:
  /// **'{count} action(s) en attente de synchronisation'**
  String pendingSync(int count);

  /// No description provided for @syncNow.
  ///
  /// In fr, this message translates to:
  /// **'Synchroniser'**
  String get syncNow;

  /// No description provided for @done.
  ///
  /// In fr, this message translates to:
  /// **'Fait'**
  String get done;

  /// No description provided for @queuedOffline.
  ///
  /// In fr, this message translates to:
  /// **'Hors ligne : action enregistrée, elle sera envoyée plus tard.'**
  String get queuedOffline;

  /// No description provided for @error.
  ///
  /// In fr, this message translates to:
  /// **'Erreur : {message}'**
  String error(String message);

  /// No description provided for @retry.
  ///
  /// In fr, this message translates to:
  /// **'Réessayer'**
  String get retry;

  /// No description provided for @required.
  ///
  /// In fr, this message translates to:
  /// **'Obligatoire'**
  String get required;

  /// No description provided for @cancel.
  ///
  /// In fr, this message translates to:
  /// **'Annuler'**
  String get cancel;
}

class _AppLocalizationsDelegate
    extends LocalizationsDelegate<AppLocalizations> {
  const _AppLocalizationsDelegate();

  @override
  Future<AppLocalizations> load(Locale locale) {
    return SynchronousFuture<AppLocalizations>(lookupAppLocalizations(locale));
  }

  @override
  bool isSupported(Locale locale) =>
      <String>['en', 'fr'].contains(locale.languageCode);

  @override
  bool shouldReload(_AppLocalizationsDelegate old) => false;
}

AppLocalizations lookupAppLocalizations(Locale locale) {
  // Lookup logic when only language code is specified.
  switch (locale.languageCode) {
    case 'en':
      return AppLocalizationsEn();
    case 'fr':
      return AppLocalizationsFr();
  }

  throw FlutterError(
    'AppLocalizations.delegate failed to load unsupported locale "$locale". This is likely '
    'an issue with the localizations generation tool. Please file an issue '
    'on GitHub with a reproducible sample app and the gen-l10n configuration '
    'that was used.',
  );
}
