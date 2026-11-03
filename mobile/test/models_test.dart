import 'package:flutter_test/flutter_test.dart';
import 'package:opes_hotel/core/models.dart';

void main() {
  test('availability flattens into offers', () {
    final offers = Offer.listFromAvailability({
      'arrival': '2026-12-01',
      'departure': '2026-12-03',
      'results': [
        {
          'hotel': {'id': 1, 'code': 'DLA1', 'name': 'Opes Douala', 'city': 'Douala'},
          'offers': [
            {
              'room_type': {'id': 4, 'code': 'STD', 'name': 'Standard'},
              'rate_plan': {'id': 9, 'code': 'BAR', 'name': 'Best rate', 'refundable': true},
              'rooms_left': 3, 'total': 90000, 'currency': 'XAF', 'nights': 2,
            }
          ]
        },
        {'hotel': {'id': 2, 'code': 'YDE', 'name': 'Opes Yaounde'}, 'offers': []},
      ],
    });
    expect(offers, hasLength(1));
    final o = offers.single;
    expect([o.hotelCode, o.roomTypeId, o.ratePlanId, o.total, o.nights, o.roomsLeft, o.refundable], ['DLA1', 4, 9, 90000, 2, 3, true]);
  });

  test('booking lookup and json round trip', () {
    final b = Booking.fromLookup({
      'number': 'R-000123', 'status': 'confirmed', 'arrival': '2026-12-01T00:00:00.000000Z', 'departure': '2026-12-03',
      'total_amount': 90000, 'currency': 'XAF', 'hotel': {'code': 'DLA1', 'name': 'Opes Douala'}, 'room_type': 'Standard',
    }, 'Mbarga');
    expect(b.arrival, '2026-12-01');
    expect(b.hotelName, 'Opes Douala');
    final c = Booking.fromJson(b.toJson());
    expect([c.confirmation, c.lastName, c.total, c.roomType], ['R-000123', 'Mbarga', 90000, 'Standard']);
  });

  test('staff user, tasks, work orders, payslips', () {
    final u = StaffUser.fromJson({'id': 5, 'name': 'Awa', 'email': 'a@x.cm', 'hotels': [{'id': 1, 'code': 'DLA1', 'name': 'Douala'}], 'permissions': ['housekeeping.view']});
    expect(u.hotels.single.code, 'DLA1');
    expect(StaffUser.fromJson(u.toJson()).permissions, ['housekeeping.view']);

    final task = HkTask.fromJson({'id': 7, 'room_id': 3, 'kind': 'departure', 'status': 'pending', 'priority': 2, 'room': {'id': 3, 'number': '101'}});
    expect([task.id, task.roomNumber, task.status], [7, '101', 'pending']);

    final wos = WorkOrder.listFrom({'data': [{'id': 1, 'number': 'WO-1', 'title': 'Leak', 'status': 'assigned', 'priority': 'high', 'room': {'number': '204'}}], 'total': 1});
    expect(wos.single.roomNumber, '204');

    final p = Payslip.fromJson({'id': 2, 'gross': '250000', 'net': 201500, 'run': {'period': '2026-10', 'status': 'paid'}});
    expect([p.period, p.gross, p.net], ['2026-10', 250000, 201500]);
  });

  test('FCFA formatting', () {
    expect(formatFcfa(1234567), '1 234 567 FCFA');
    expect(formatFcfa(900, 'XAF'), '900 FCFA');
  });
}
