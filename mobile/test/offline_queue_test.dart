import 'package:flutter_test/flutter_test.dart';
import 'package:opes_hotel/core/api.dart';
import 'package:opes_hotel/core/offline_queue.dart';

void main() {
  late OfflineQueue q;
  setUp(() => q = OfflineQueue(MemoryQueueStore()));

  test('sends immediately when online', () async {
    final sent = <QueuedAction>[];
    final r = await q.submit(path: 'hr/clock/in', label: 'in', send: (a) async => sent.add(a));
    expect(r, SubmitResult.sent);
    expect(sent.single.idempotencyKey, hasLength(36));
    expect(await q.pendingCount(), 0);
  });

  test('queues when offline and syncs in order with the same key', () async {
    Future<void> offline(QueuedAction a) async => throw ApiException('no route', offline: true);
    expect(await q.submit(path: 'housekeeping/tasks/1/start', label: 'start', hotelId: 3, send: offline), SubmitResult.queued);
    expect(await q.submit(path: 'housekeeping/tasks/1/finish', label: 'finish', hotelId: 3, send: offline), SubmitResult.queued);
    expect(await q.pendingCount(), 2);

    final keys = (await q.store.all()).map((a) => a.idempotencyKey).toList();
    final sent = <QueuedAction>[];
    final rejected = await q.sync((a) async => sent.add(a));
    expect(rejected, isEmpty);
    expect(sent.map((a) => a.path), ['housekeeping/tasks/1/start', 'housekeeping/tasks/1/finish']);
    expect(sent.map((a) => a.idempotencyKey), keys);
    expect(sent.first.hotelId, 3);
    expect(await q.pendingCount(), 0);
  });

  test('a server refusal is not queued', () async {
    expect(
      () => q.submit(path: 'x', label: 'x', send: (a) async => throw ApiException('invalid', status: 422)),
      throwsA(isA<ApiException>()),
    );
    expect(await q.pendingCount(), 0);
  });

  test('sync stops on offline and drops rejected actions', () async {
    Future<void> offline(QueuedAction a) async => throw ApiException('down', offline: true);
    await q.submit(path: 'a', label: 'a', send: offline);
    await q.submit(path: 'b', label: 'b', send: offline);
    await q.submit(path: 'c', label: 'c', send: offline);

    final before = (await q.store.all()).first.attempts;
    await q.sync(offline);
    expect((await q.store.all()).first.attempts, before + 1);
    expect(await q.pendingCount(), 3);

    final rejected = await q.sync((a) async {
      if (a.path == 'b') throw ApiException('conflict', status: 409);
    });
    expect(rejected, ['b: conflict']);
    expect(await q.pendingCount(), 0);
  });

  test('a 5xx keeps the action queued', () async {
    final r = await q.submit(path: 'a', label: 'a', send: (a) async => throw ApiException('boom', status: 503));
    expect(r, SubmitResult.queued);
    expect(await q.pendingCount(), 1);
  });

  test('uuid v4 format', () {
    expect(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$').hasMatch(uuidV4()), isTrue);
  });
}
