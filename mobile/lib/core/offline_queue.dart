import 'dart:convert';
import 'dart:math';

import 'package:sqflite/sqflite.dart';

import 'api.dart';

/// One staff mutation waiting to reach the server. The [idempotencyKey] is
/// created once, when the action is taken, and sent on every retry so the
/// server applies it only once.
class QueuedAction {
  QueuedAction({required this.id, required this.path, required this.body, required this.idempotencyKey, required this.hotelId, required this.label, required this.createdAt, this.attempts = 0, this.lastError});

  final String id;
  final String path;
  final Map<String, dynamic> body;
  final String idempotencyKey;
  final int? hotelId;
  final String label;
  final DateTime createdAt;
  int attempts;
  String? lastError;

  Map<String, dynamic> toRow() => {
        'id': id,
        'path': path,
        'body': jsonEncode(body),
        'idempotency_key': idempotencyKey,
        'hotel_id': hotelId,
        'label': label,
        'created_at': createdAt.toIso8601String(),
        'attempts': attempts,
        'last_error': lastError,
      };

  factory QueuedAction.fromRow(Map<String, dynamic> r) => QueuedAction(
        id: r['id'] as String,
        path: r['path'] as String,
        body: Map<String, dynamic>.from(jsonDecode(r['body'] as String)),
        idempotencyKey: r['idempotency_key'] as String,
        hotelId: r['hotel_id'] as int?,
        label: r['label'] as String,
        createdAt: DateTime.parse(r['created_at'] as String),
        attempts: (r['attempts'] as int?) ?? 0,
        lastError: r['last_error'] as String?,
      );
}

/// Storage behind the queue. sqflite on the device, memory in tests.
abstract class QueueStore {
  Future<List<QueuedAction>> all();
  Future<void> put(QueuedAction a);
  Future<void> remove(String id);
}

class MemoryQueueStore implements QueueStore {
  final _rows = <String, Map<String, dynamic>>{};
  @override
  Future<List<QueuedAction>> all() async {
    final list = _rows.values.map(QueuedAction.fromRow).toList()..sort((a, b) => a.createdAt.compareTo(b.createdAt));
    return list;
  }

  @override
  Future<void> put(QueuedAction a) async => _rows[a.id] = a.toRow();
  @override
  Future<void> remove(String id) async => _rows.remove(id);
}

class SqfliteQueueStore implements QueueStore {
  Database? _db;
  Future<Database> _open() async => _db ??= await openDatabase(
        'opes_queue.db',
        version: 1,
        onCreate: (db, _) => db.execute('CREATE TABLE actions (id TEXT PRIMARY KEY, path TEXT, body TEXT, idempotency_key TEXT, hotel_id INTEGER, label TEXT, created_at TEXT, attempts INTEGER, last_error TEXT)'),
      );

  @override
  Future<List<QueuedAction>> all() async =>
      (await (await _open()).query('actions', orderBy: 'created_at')).map(QueuedAction.fromRow).toList();
  @override
  Future<void> put(QueuedAction a) async =>
      (await _open()).insert('actions', a.toRow(), conflictAlgorithm: ConflictAlgorithm.replace);
  @override
  Future<void> remove(String id) async => (await _open()).delete('actions', where: 'id = ?', whereArgs: [id]);
}

/// Random UUID v4, enough for idempotency keys.
String uuidV4([Random? rnd]) {
  final r = rnd ?? Random.secure();
  final b = List<int>.generate(16, (_) => r.nextInt(256));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  final h = b.map((x) => x.toRadixString(16).padLeft(2, '0')).join();
  return '${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-${h.substring(16, 20)}-${h.substring(20)}';
}

typedef Sender = Future<void> Function(QueuedAction a);

enum SubmitResult { sent, queued }

class OfflineQueue {
  OfflineQueue(this.store);
  final QueueStore store;

  Future<int> pendingCount() async => (await store.all()).length;

  /// Tries to send now; when the server is unreachable the action is stored
  /// with its key and sent later by [sync]. Server-side refusals (4xx) are
  /// thrown to the caller and not queued.
  Future<SubmitResult> submit({required String path, Map<String, dynamic> body = const {}, required String label, int? hotelId, required Sender send}) async {
    final a = QueuedAction(id: uuidV4(), path: path, body: body, idempotencyKey: uuidV4(), hotelId: hotelId, label: label, createdAt: DateTime.now());
    // Older actions go first so order is kept (start before finish).
    if (await pendingCount() > 0) {
      await store.put(a);
      await sync(send);
      return (await store.all()).any((x) => x.id == a.id) ? SubmitResult.queued : SubmitResult.sent;
    }
    try {
      await send(a);
      return SubmitResult.sent;
    } on ApiException catch (e) {
      if (!e.offline && (e.status ?? 500) < 500) rethrow;
      a.lastError = e.message;
      await store.put(a);
      return SubmitResult.queued;
    }
  }

  /// Sends pending actions in order. Stops at the first offline error.
  /// An action the server refuses (4xx other than 408/429) is dropped so it
  /// does not block the queue; its error is returned.
  Future<List<String>> sync(Sender send) async {
    final rejected = <String>[];
    for (final a in await store.all()) {
      try {
        await send(a);
        await store.remove(a.id);
      } on ApiException catch (e) {
        final retryable = e.offline || e.status == null || e.status! >= 500 || e.status == 408 || e.status == 429;
        if (retryable) {
          a.attempts++;
          a.lastError = e.message;
          await store.put(a);
          break;
        }
        await store.remove(a.id);
        rejected.add('${a.label}: ${e.message}');
      }
    }
    return rejected;
  }
}
