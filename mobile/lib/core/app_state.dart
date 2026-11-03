import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/legacy.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import 'models.dart';
import 'offline_queue.dart';

enum AppMode { none, guest, staff }

/// All app-wide state in one ChangeNotifier, kept on device with
/// shared_preferences. Small app, simple state.
class AppState extends ChangeNotifier {
  AppState(this.prefs, this.queue) {
    baseUrl = prefs.getString('base_url') ?? 'http://192.168.1.189:8000';
    locale = Locale(prefs.getString('locale') ?? 'fr');
    mode = AppMode.values.byName(prefs.getString('mode') ?? 'none');
    groupSlug = prefs.getString('group_slug');
    memberNumber = prefs.getString('member_number');
    token = prefs.getString('token');
    hotelId = prefs.getInt('hotel_id');
    final u = prefs.getString('user');
    user = u == null ? null : StaffUser.fromJson(Map<String, dynamic>.from(jsonDecode(u)));
    bookings = (prefs.getStringList('bookings') ?? []).map((s) => Booking.fromJson(Map<String, dynamic>.from(jsonDecode(s)))).toList();
    refreshPending();
  }

  final SharedPreferences prefs;
  final OfflineQueue queue;

  late String baseUrl;
  late Locale locale;
  late AppMode mode;
  String? groupSlug;
  String? memberNumber;
  String? token;
  int? hotelId;
  StaffUser? user;
  List<Booking> bookings = [];
  int pending = 0;
  bool syncing = false;

  ApiClient get api => ApiClient(baseUrl: baseUrl, token: token, hotelId: hotelId);
  bool get signedIn => token != null && user != null;
  bool can(String perm) => user?.permissions.contains(perm) ?? false;

  Future<void> setBaseUrl(String v) async {
    baseUrl = v.trim();
    await prefs.setString('base_url', baseUrl);
    notifyListeners();
  }

  Future<void> setLocale(String code) async {
    locale = Locale(code);
    await prefs.setString('locale', code);
    notifyListeners();
  }

  Future<void> setMode(AppMode m) async {
    mode = m;
    await prefs.setString('mode', m.name);
    notifyListeners();
  }

  Future<void> setGroup(String? slug) async {
    groupSlug = slug?.trim().toLowerCase();
    groupSlug == null ? await prefs.remove('group_slug') : await prefs.setString('group_slug', groupSlug!);
    notifyListeners();
  }

  Future<void> setMemberNumber(String v) async {
    memberNumber = v.trim();
    await prefs.setString('member_number', memberNumber!);
    notifyListeners();
  }

  Future<void> saveBooking(Booking b) async {
    bookings = [b, ...bookings.where((x) => x.confirmation != b.confirmation)];
    await _persistBookings();
  }

  Future<void> removeBooking(String confirmation) async {
    bookings = bookings.where((x) => x.confirmation != confirmation).toList();
    await _persistBookings();
  }

  Future<void> _persistBookings() async {
    await prefs.setStringList('bookings', bookings.map((b) => jsonEncode(b.toJson())).toList());
    notifyListeners();
  }

  /// First-party staff login: POST /api/v1/auth/login returns a Passport
  /// personal access token.
  Future<void> login(String email, String password, {String? mfa}) async {
    final r = await ApiClient(baseUrl: baseUrl).post('auth/login', {
      'email': email,
      'password': password,
      if (mfa != null && mfa.isNotEmpty) 'mfa_code': mfa,
      'device_name': 'opes-mobile',
    });
    token = r['access_token'] as String;
    user = StaffUser.fromJson(Map<String, dynamic>.from(r['user']));
    await prefs.setString('token', token!);
    await prefs.setString('user', jsonEncode(user!.toJson()));
    if (user!.hotels.length == 1) await setHotel(user!.hotels.first.id);
    notifyListeners();
  }

  Future<void> setHotel(int id) async {
    hotelId = id;
    await prefs.setInt('hotel_id', id);
    notifyListeners();
  }

  Future<void> logout() async {
    try {
      await api.post('auth/logout');
    } catch (_) {}
    token = null;
    user = null;
    hotelId = null;
    for (final k in ['token', 'user', 'hotel_id']) {
      await prefs.remove(k);
    }
    notifyListeners();
  }

  /// Sends one queued action with the hotel it was taken in.
  Future<void> _send(QueuedAction a) async {
    await ApiClient(baseUrl: baseUrl, token: token, hotelId: a.hotelId ?? hotelId).post(a.path, a.body, a.idempotencyKey);
  }

  /// Staff mutation that survives being offline.
  Future<SubmitResult> mutate(String path, String label, [Map<String, dynamic> body = const {}]) async {
    try {
      return await queue.submit(path: path, body: body, label: label, hotelId: hotelId, send: _send);
    } finally {
      await refreshPending();
    }
  }

  Future<List<String>> sync() async {
    if (syncing || !signedIn) return [];
    syncing = true;
    notifyListeners();
    try {
      return await queue.sync(_send);
    } finally {
      syncing = false;
      await refreshPending();
    }
  }

  Future<void> refreshPending() async {
    pending = await queue.pendingCount();
    notifyListeners();
  }
}

final appStateProvider = ChangeNotifierProvider<AppState>((ref) => throw UnimplementedError('override in main'));
