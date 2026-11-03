// Plain models parsed from the API JSON. Parsing is defensive: the API sends
// integers for money (FCFA) and ids, but tolerates strings too.

int _int(dynamic v) => v is int ? v : int.tryParse('${v ?? ''}') ?? (v is num ? v.toInt() : 0);
String _str(dynamic v) => v == null ? '' : '$v';

class HotelRef {
  HotelRef({required this.id, required this.code, required this.name, this.city = ''});
  final int id;
  final String code;
  final String name;
  final String city;

  factory HotelRef.fromJson(Map<String, dynamic> j) =>
      HotelRef(id: _int(j['id']), code: _str(j['code']), name: _str(j['name']), city: _str(j['city']));
  Map<String, dynamic> toJson() => {'id': id, 'code': code, 'name': name, 'city': city};
}

class StaffUser {
  StaffUser({required this.id, required this.name, required this.email, required this.hotels, required this.permissions});
  final int id;
  final String name;
  final String email;
  final List<HotelRef> hotels;
  final List<String> permissions;

  factory StaffUser.fromJson(Map<String, dynamic> j) => StaffUser(
        id: _int(j['id']),
        name: _str(j['name']),
        email: _str(j['email']),
        hotels: ((j['hotels'] as List?) ?? []).map((h) => HotelRef.fromJson(Map<String, dynamic>.from(h))).toList(),
        permissions: ((j['permissions'] as List?) ?? []).map((p) => '$p').toList(),
      );
  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'email': email,
        'hotels': hotels.map((h) => h.toJson()).toList(),
        'permissions': permissions,
      };
}

class Offer {
  Offer({
    required this.hotelCode,
    required this.hotelName,
    required this.roomTypeId,
    required this.roomTypeName,
    required this.ratePlanId,
    required this.ratePlanName,
    required this.total,
    required this.currency,
    required this.nights,
    required this.roomsLeft,
    required this.refundable,
  });
  final String hotelCode, hotelName, roomTypeName, ratePlanName, currency;
  final int roomTypeId, ratePlanId, total, nights, roomsLeft;
  final bool refundable;

  /// Flattens the availability answer: results[].offers[] into one list.
  static List<Offer> listFromAvailability(Map<String, dynamic> j) {
    final out = <Offer>[];
    for (final r in (j['results'] as List? ?? [])) {
      final hotel = Map<String, dynamic>.from(r['hotel'] ?? {});
      for (final o in (r['offers'] as List? ?? [])) {
        final rt = Map<String, dynamic>.from(o['room_type'] ?? {});
        final rp = Map<String, dynamic>.from(o['rate_plan'] ?? {});
        out.add(Offer(
          hotelCode: _str(hotel['code']),
          hotelName: _str(hotel['name']),
          roomTypeId: _int(rt['id']),
          roomTypeName: _str(rt['name']),
          ratePlanId: _int(rp['id']),
          ratePlanName: _str(rp['name']),
          total: _int(o['total']),
          currency: _str(o['currency']).isEmpty ? 'XAF' : _str(o['currency']),
          nights: _int(o['nights']),
          roomsLeft: _int(o['rooms_left']),
          refundable: rp['refundable'] == true,
        ));
      }
    }
    return out;
  }
}

class Booking {
  Booking({required this.confirmation, required this.lastName, required this.status, this.hotelName = '', this.arrival = '', this.departure = '', this.total = 0, this.currency = 'XAF', this.roomType = ''});
  final String confirmation, lastName, status, hotelName, arrival, departure, currency, roomType;
  final int total;

  /// From POST reservations/lookup.
  factory Booking.fromLookup(Map<String, dynamic> j, String lastName) => Booking(
        confirmation: _str(j['number']),
        lastName: lastName,
        status: _str(j['status']),
        hotelName: _str((j['hotel'] as Map?)?['name']),
        arrival: _str(j['arrival']).split('T').first,
        departure: _str(j['departure']).split('T').first,
        total: _int(j['total_amount']),
        currency: _str(j['currency']).isEmpty ? 'XAF' : _str(j['currency']),
        roomType: _str(j['room_type']),
      );

  factory Booking.fromJson(Map<String, dynamic> j) => Booking(
        confirmation: _str(j['confirmation']),
        lastName: _str(j['last_name']),
        status: _str(j['status']),
        hotelName: _str(j['hotel_name']),
        arrival: _str(j['arrival']),
        departure: _str(j['departure']),
        total: _int(j['total']),
        currency: _str(j['currency']),
        roomType: _str(j['room_type']),
      );
  Map<String, dynamic> toJson() => {
        'confirmation': confirmation,
        'last_name': lastName,
        'status': status,
        'hotel_name': hotelName,
        'arrival': arrival,
        'departure': departure,
        'total': total,
        'currency': currency,
        'room_type': roomType,
      };
}

class HkTask {
  HkTask({required this.id, required this.roomId, required this.roomNumber, required this.kind, required this.status, required this.priority});
  final int id, roomId, priority;
  final String roomNumber, kind, status;

  factory HkTask.fromJson(Map<String, dynamic> j) {
    final room = Map<String, dynamic>.from(j['room'] ?? {});
    return HkTask(
      id: _int(j['id']),
      roomId: _int(j['room_id'] ?? room['id']),
      roomNumber: _str(room['number']),
      kind: _str(j['kind']),
      status: _str(j['status']),
      priority: _int(j['priority']),
    );
  }
}

class WorkOrder {
  WorkOrder({required this.id, required this.number, required this.title, required this.status, required this.priority, this.roomNumber = ''});
  final int id;
  final String number, title, status, priority, roomNumber;

  factory WorkOrder.fromJson(Map<String, dynamic> j) => WorkOrder(
        id: _int(j['id']),
        number: _str(j['number']),
        title: _str(j['title']),
        status: _str(j['status']),
        priority: _str(j['priority']),
        roomNumber: _str((j['room'] as Map?)?['number']),
      );

  /// index is paginated ({data: [...]}) but accept a bare list too.
  static List<WorkOrder> listFrom(dynamic body) {
    final list = body is Map ? (body['data'] as List? ?? []) : (body as List? ?? []);
    return list.map((e) => WorkOrder.fromJson(Map<String, dynamic>.from(e))).toList();
  }
}

class Payslip {
  Payslip({required this.id, required this.period, required this.gross, required this.net, required this.status});
  final int id, gross, net;
  final String period, status;

  factory Payslip.fromJson(Map<String, dynamic> j) {
    final run = Map<String, dynamic>.from(j['run'] ?? {});
    return Payslip(id: _int(j['id']), period: _str(run['period']), gross: _int(j['gross']), net: _int(j['net']), status: _str(run['status']));
  }
}

class LeaveType {
  LeaveType({required this.id, required this.name});
  final int id;
  final String name;
  factory LeaveType.fromJson(Map<String, dynamic> j) => LeaveType(id: _int(j['id']), name: _str(j['name']));
}

String formatFcfa(int amount, [String currency = 'FCFA']) {
  final s = amount.abs().toString();
  final b = StringBuffer();
  for (var i = 0; i < s.length; i++) {
    if (i > 0 && (s.length - i) % 3 == 0) b.write(' ');
    b.write(s[i]);
  }
  return '${amount < 0 ? '-' : ''}$b ${currency == 'XAF' ? 'FCFA' : currency}';
}
