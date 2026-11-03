import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../core/app_state.dart';
import '../core/models.dart';
import '../theme.dart';
import 'common.dart';
import 'home.dart';

class GuestHome extends ConsumerStatefulWidget {
  const GuestHome({super.key});
  @override
  ConsumerState<GuestHome> createState() => _GuestHomeState();
}

class _GuestHomeState extends ConsumerState<GuestHome> {
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    final l = t(context);
    final s = ref.watch(appStateProvider);
    final pages = [s.groupSlug == null ? const GroupPicker() : const BookScreen(), const MyBookingsScreen(), const LoyaltyScreen()];
    return Scaffold(
      appBar: AppBar(
        title: Text(s.groupSlug == null ? l.appTitle : s.groupSlug!),
        actions: [
          if (s.groupSlug != null) IconButton(tooltip: l.groupSlug, icon: const Icon(Icons.apartment), onPressed: () => s.setGroup(null)),
          const SettingsAction(),
        ],
      ),
      body: pages[_tab],
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) => setState(() => _tab = i),
        destinations: [
          NavigationDestination(icon: const Icon(Icons.search), label: l.book),
          NavigationDestination(icon: const Icon(Icons.event_note_outlined), label: l.myBookings),
          NavigationDestination(icon: const Icon(Icons.card_membership), label: l.loyaltyCard),
        ],
      ),
    );
  }
}

class GroupPicker extends ConsumerStatefulWidget {
  const GroupPicker({super.key});
  @override
  ConsumerState<GroupPicker> createState() => _GroupPickerState();
}

class _GroupPickerState extends ConsumerState<GroupPicker> {
  final _slug = TextEditingController();
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    final l = t(context);
    return ListView(padding: const EdgeInsets.all(16), children: [
      TextField(controller: _slug, decoration: InputDecoration(labelText: l.groupSlug), textInputAction: TextInputAction.done),
      const SizedBox(height: 12),
      FilledButton(
        onPressed: _busy
            ? null
            : () async {
                final s = ref.read(appStateProvider);
                final slug = _slug.text.trim().toLowerCase();
                if (slug.isEmpty) return;
                setState(() => _busy = true);
                try {
                  await s.api.get('booking/$slug/hotels');
                  await s.setGroup(slug);
                } catch (e) {
                  if (context.mounted) toast(context, errorText(context, e));
                } finally {
                  if (mounted) setState(() => _busy = false);
                }
              },
        child: Text(l.continueLabel),
      ),
    ]);
  }
}

class BookScreen extends ConsumerStatefulWidget {
  const BookScreen({super.key});
  @override
  ConsumerState<BookScreen> createState() => _BookScreenState();
}

class _BookScreenState extends ConsumerState<BookScreen> {
  DateTime _arrival = DateTime.now();
  DateTime _departure = DateTime.now().add(const Duration(days: 1));
  int _adults = 1, _children = 0;
  List<Offer>? _offers;
  bool _busy = false;

  Future<void> _search() async {
    final s = ref.read(appStateProvider);
    setState(() => _busy = true);
    try {
      final r = await s.api.get('booking/${s.groupSlug}/availability', {
        'arrival': isoDate(_arrival),
        'departure': isoDate(_departure),
        'adults': '$_adults',
        'children': '$_children',
        if (s.memberNumber != null && s.memberNumber!.isNotEmpty) 'member_number': s.memberNumber!,
      });
      setState(() => _offers = Offer.listFromAvailability(Map<String, dynamic>.from(r)));
    } catch (e) {
      if (mounted) toast(context, errorText(context, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _counter(String label, int v, int min, int max, ValueChanged<int> set) => Row(children: [
        Expanded(child: Text(label, style: const TextStyle(fontSize: 17))),
        IconButton(onPressed: v > min ? () => set(v - 1) : null, icon: const Icon(Icons.remove_circle_outline)),
        Text('$v', style: const TextStyle(fontSize: 18)),
        IconButton(onPressed: v < max ? () => set(v + 1) : null, icon: const Icon(Icons.add_circle_outline)),
      ]);

  @override
  Widget build(BuildContext context) {
    final l = t(context);
    return ListView(padding: const EdgeInsets.all(16), children: [
      DateField(label: l.arrival, value: _arrival, onChanged: (d) => setState(() {
            _arrival = d;
            if (!_departure.isAfter(d)) _departure = d.add(const Duration(days: 1));
          })),
      const SizedBox(height: 12),
      DateField(label: l.departure, value: _departure, onChanged: (d) => setState(() => _departure = d.isAfter(_arrival) ? d : _arrival.add(const Duration(days: 1)))),
      _counter(l.adults, _adults, 1, 8, (v) => setState(() => _adults = v)),
      _counter(l.children, _children, 0, 6, (v) => setState(() => _children = v)),
      FilledButton(onPressed: _busy ? null : _search, child: Text(l.search)),
      const SizedBox(height: 12),
      if (_offers != null && _offers!.isEmpty) Padding(padding: const EdgeInsets.all(16), child: Text(l.noOffers, style: const TextStyle(fontSize: 17))),
      for (final o in _offers ?? <Offer>[])
        Card(
          child: ListTile(
            contentPadding: const EdgeInsets.all(12),
            title: Text('${o.roomTypeName} · ${o.ratePlanName}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
            subtitle: Text('${o.hotelName}\n${l.nights(o.nights)} · ${l.roomsLeft(o.roomsLeft)} · ${o.refundable ? l.refundable : l.nonRefundable}', style: const TextStyle(fontSize: 15)),
            isThreeLine: true,
            trailing: Text(formatFcfa(o.total, o.currency), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700, color: navy)),
            onTap: () => Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => BookingFormScreen(offer: o, arrival: _arrival, departure: _departure, adults: _adults, children: _children))),
          ),
        ),
    ]);
  }
}

class BookingFormScreen extends ConsumerStatefulWidget {
  const BookingFormScreen({super.key, required this.offer, required this.arrival, required this.departure, required this.adults, required this.children});
  final Offer offer;
  final DateTime arrival, departure;
  final int adults, children;
  @override
  ConsumerState<BookingFormScreen> createState() => _BookingFormScreenState();
}

class _BookingFormScreenState extends ConsumerState<BookingFormScreen> {
  final _form = GlobalKey<FormState>();
  final _first = TextEditingController(), _last = TextEditingController(), _email = TextEditingController(), _phone = TextEditingController(), _notes = TextEditingController();
  bool _terms = false, _busy = false;

  Future<void> _submit() async {
    if (!_form.currentState!.validate() || !_terms) return;
    final s = ref.read(appStateProvider);
    final l = t(context);
    setState(() => _busy = true);
    try {
      final o = widget.offer;
      final r = await s.api.post('booking/${s.groupSlug}/reservations', {
        'hotel_code': o.hotelCode,
        'room_type_id': o.roomTypeId,
        'rate_plan_id': o.ratePlanId,
        'arrival': isoDate(widget.arrival),
        'departure': isoDate(widget.departure),
        'adults': widget.adults,
        'children': widget.children,
        'guest': {'first_name': _first.text.trim(), 'last_name': _last.text.trim(), 'email': _email.text.trim(), 'phone': _phone.text.trim()},
        if (_notes.text.trim().isNotEmpty) 'special_requests': _notes.text.trim(),
        if (s.memberNumber != null && s.memberNumber!.isNotEmpty) 'member_number': s.memberNumber,
        'accept_terms': true,
      });
      final number = '${r['confirmation']}';
      await s.saveBooking(Booking(
        confirmation: number,
        lastName: _last.text.trim(),
        status: '${r['status']}',
        hotelName: o.hotelName,
        arrival: isoDate(widget.arrival),
        departure: isoDate(widget.departure),
        total: (r['total'] as num?)?.toInt() ?? o.total,
        currency: o.currency,
        roomType: o.roomTypeName,
      ));
      if (!mounted) return;
      toast(context, l.bookingConfirmed(number));
      Navigator.of(context).pop();
    } catch (e) {
      if (mounted) toast(context, errorText(context, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = t(context);
    String? req(String? v) => (v == null || v.trim().isEmpty) ? l.required : null;
    final o = widget.offer;
    return Scaffold(
      appBar: AppBar(title: Text(l.book)),
      body: Form(
        key: _form,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          Text('${o.hotelName}\n${o.roomTypeName} · ${o.ratePlanName}\n${isoDate(widget.arrival)} → ${isoDate(widget.departure)}', style: const TextStyle(fontSize: 17)),
          Text(formatFcfa(o.total, o.currency), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700, color: navy)),
          const SizedBox(height: 16),
          TextFormField(controller: _first, decoration: InputDecoration(labelText: l.firstName), validator: req),
          const SizedBox(height: 12),
          TextFormField(controller: _last, decoration: InputDecoration(labelText: l.lastName), validator: req),
          const SizedBox(height: 12),
          TextFormField(controller: _email, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: l.email), validator: req),
          const SizedBox(height: 12),
          TextFormField(controller: _phone, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: l.phone), validator: req),
          const SizedBox(height: 12),
          TextFormField(controller: _notes, maxLines: 3, decoration: InputDecoration(labelText: l.specialRequests)),
          CheckboxListTile(value: _terms, onChanged: (v) => setState(() => _terms = v ?? false), title: Text(l.acceptTerms), contentPadding: EdgeInsets.zero),
          FilledButton(onPressed: _busy || !_terms ? null : _submit, child: Text(l.confirmBooking)),
        ]),
      ),
    );
  }
}

class MyBookingsScreen extends ConsumerStatefulWidget {
  const MyBookingsScreen({super.key});
  @override
  ConsumerState<MyBookingsScreen> createState() => _MyBookingsScreenState();
}

class _MyBookingsScreenState extends ConsumerState<MyBookingsScreen> {
  final _conf = TextEditingController(), _last = TextEditingController();
  bool _busy = false;

  Future<void> _lookup(String confirmation, String lastName) async {
    final s = ref.read(appStateProvider);
    if (s.groupSlug == null) return;
    setState(() => _busy = true);
    try {
      final r = await s.api.post('booking/${s.groupSlug}/reservations/lookup', {'confirmation': confirmation, 'last_name': lastName});
      await s.saveBooking(Booking.fromLookup(Map<String, dynamic>.from(r), lastName));
      _conf.clear();
    } catch (e) {
      if (mounted) toast(context, errorText(context, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = t(context);
    final s = ref.watch(appStateProvider);
    return ListView(padding: const EdgeInsets.all(16), children: [
      Text(l.findBooking, style: Theme.of(context).textTheme.titleMedium),
      const SizedBox(height: 8),
      TextField(controller: _conf, decoration: InputDecoration(labelText: l.confirmationNumber)),
      const SizedBox(height: 12),
      TextField(controller: _last, decoration: InputDecoration(labelText: l.lastName)),
      const SizedBox(height: 12),
      FilledButton(
        onPressed: _busy || s.groupSlug == null ? null : () => _lookup(_conf.text.trim(), _last.text.trim()),
        child: Text(l.search),
      ),
      const SizedBox(height: 16),
      if (s.bookings.isEmpty) Text(l.noBookings, style: const TextStyle(fontSize: 17)),
      for (final b in s.bookings)
        Card(
          child: ListTile(
            contentPadding: const EdgeInsets.all(12),
            title: Text('${b.confirmation} · ${b.status}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
            subtitle: Text('${b.hotelName} ${b.roomType}\n${b.arrival} → ${b.departure} · ${formatFcfa(b.total, b.currency)}', style: const TextStyle(fontSize: 15)),
            isThreeLine: true,
            onTap: () => _lookup(b.confirmation, b.lastName),
            trailing: IconButton(tooltip: l.remove, icon: const Icon(Icons.delete_outline), onPressed: () => s.removeBooking(b.confirmation)),
          ),
        ),
    ]);
  }
}

class LoyaltyScreen extends ConsumerStatefulWidget {
  const LoyaltyScreen({super.key});
  @override
  ConsumerState<LoyaltyScreen> createState() => _LoyaltyScreenState();
}

class _LoyaltyScreenState extends ConsumerState<LoyaltyScreen> {
  late final _num = TextEditingController(text: ref.read(appStateProvider).memberNumber ?? '');

  @override
  Widget build(BuildContext context) {
    final l = t(context);
    final s = ref.watch(appStateProvider);
    final n = s.memberNumber;
    return ListView(padding: const EdgeInsets.all(16), children: [
      if (n != null && n.isNotEmpty)
        Card(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(children: [
              QrImageView(key: const Key('loyalty-qr'), data: n, size: 220, backgroundColor: Colors.white, eyeStyle: const QrEyeStyle(eyeShape: QrEyeShape.square, color: navy), dataModuleStyle: const QrDataModuleStyle(dataModuleShape: QrDataModuleShape.square, color: navy)),
              const SizedBox(height: 12),
              Text(n, style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w700, letterSpacing: 2, color: navy)),
              const SizedBox(height: 4),
              Text(l.cardHelp, style: const TextStyle(fontSize: 15)),
            ]),
          ),
        ),
      const SizedBox(height: 12),
      TextField(controller: _num, decoration: InputDecoration(labelText: l.memberNumber)),
      const SizedBox(height: 12),
      FilledButton(onPressed: () => s.setMemberNumber(_num.text), child: Text(l.showCard)),
    ]);
  }
}
