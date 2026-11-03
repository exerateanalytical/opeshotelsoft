// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for English (`en`).
class AppLocalizationsEn extends AppLocalizations {
  AppLocalizationsEn([String locale = 'en']) : super(locale);

  @override
  String get appTitle => 'OpesHotel';

  @override
  String get settings => 'Settings';

  @override
  String get serverUrl => 'Server address';

  @override
  String get serverUrlHint => 'e.g. http://192.168.1.189:8000';

  @override
  String get language => 'Language';

  @override
  String get save => 'Save';

  @override
  String get saved => 'Saved';

  @override
  String get guest => 'Guest';

  @override
  String get staff => 'Staff';

  @override
  String get chooseMode => 'How do you want to use the app?';

  @override
  String get guestModeDesc => 'Book, see my bookings, loyalty card';

  @override
  String get staffModeDesc => 'Tasks, work orders, payslips, leave, clock';

  @override
  String get groupSlug => 'Hotel group code';

  @override
  String get continueLabel => 'Continue';

  @override
  String get book => 'Book';

  @override
  String get myBookings => 'My bookings';

  @override
  String get loyaltyCard => 'Loyalty card';

  @override
  String get arrival => 'Arrival';

  @override
  String get departure => 'Departure';

  @override
  String get adults => 'Adults';

  @override
  String get children => 'Children';

  @override
  String get search => 'Search';

  @override
  String get noOffers => 'No rooms available for these dates.';

  @override
  String nights(int count) {
    return '$count night(s)';
  }

  @override
  String roomsLeft(int count) {
    return '$count left';
  }

  @override
  String get refundable => 'Refundable';

  @override
  String get nonRefundable => 'Non-refundable';

  @override
  String get firstName => 'First name';

  @override
  String get lastName => 'Last name';

  @override
  String get email => 'Email';

  @override
  String get phone => 'Phone';

  @override
  String get specialRequests => 'Special requests';

  @override
  String get acceptTerms => 'I accept the booking terms';

  @override
  String get confirmBooking => 'Confirm booking';

  @override
  String bookingConfirmed(String number) {
    return 'Booking confirmed: $number';
  }

  @override
  String get confirmationNumber => 'Confirmation number';

  @override
  String get findBooking => 'Find a booking';

  @override
  String get noBookings => 'No saved bookings.';

  @override
  String get remove => 'Remove';

  @override
  String get memberNumber => 'Member number';

  @override
  String get showCard => 'Show card';

  @override
  String get cardHelp => 'Show this code at the front desk.';

  @override
  String get password => 'Password';

  @override
  String get mfaCode => 'Authentication code (if asked)';

  @override
  String get signIn => 'Sign in';

  @override
  String get signOut => 'Sign out';

  @override
  String get chooseHotel => 'Choose hotel';

  @override
  String get housekeeping => 'Housekeeping';

  @override
  String get roomStatus => 'Room status';

  @override
  String get workOrders => 'Work orders';

  @override
  String get payslips => 'My payslips';

  @override
  String get leave => 'Leave';

  @override
  String get clock => 'Clock';

  @override
  String get start => 'Start';

  @override
  String get finish => 'Finish';

  @override
  String get complete => 'Complete';

  @override
  String get resolution => 'Work done';

  @override
  String get noTasks => 'No tasks for you today.';

  @override
  String get noWorkOrders => 'No work orders assigned.';

  @override
  String get noPayslips => 'No payslips yet.';

  @override
  String roomNumber(String number) {
    return 'Room $number';
  }

  @override
  String get roomId => 'Room ID';

  @override
  String get newStatus => 'New status';

  @override
  String get reason => 'Reason';

  @override
  String get apply => 'Apply';

  @override
  String get leaveType => 'Leave type';

  @override
  String get startDate => 'Start date';

  @override
  String get endDate => 'End date';

  @override
  String get submit => 'Submit';

  @override
  String get clockIn => 'Clock in';

  @override
  String get clockOut => 'Clock out';

  @override
  String clockedInSince(String time) {
    return 'Clocked in since $time';
  }

  @override
  String get notClockedIn => 'Not clocked in';

  @override
  String get gross => 'Gross';

  @override
  String get net => 'Net';

  @override
  String pendingSync(int count) {
    return '$count action(s) waiting to sync';
  }

  @override
  String get syncNow => 'Sync now';

  @override
  String get done => 'Done';

  @override
  String get queuedOffline => 'Offline: action saved, it will be sent later.';

  @override
  String error(String message) {
    return 'Error: $message';
  }

  @override
  String get retry => 'Retry';

  @override
  String get required => 'Required';

  @override
  String get cancel => 'Cancel';
}
