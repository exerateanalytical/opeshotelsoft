import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

/// Thrown for any non-2xx answer. [offline] is true when the server could not
/// be reached at all, which is what tells the queue to keep the action.
class ApiException implements Exception {
  ApiException(this.message, {this.status, this.offline = false});
  final String message;
  final int? status;
  final bool offline;

  @override
  String toString() => message;
}

/// Thin JSON client for the OpesHotelSoft REST API (/api/v1).
class ApiClient {
  ApiClient({required this.baseUrl, this.token, this.hotelId, http.Client? client})
      : _http = client ?? http.Client();

  final String baseUrl;
  final String? token;
  final int? hotelId;
  final http.Client _http;

  Uri uri(String path, [Map<String, String>? query]) {
    final base = baseUrl.endsWith('/') ? baseUrl.substring(0, baseUrl.length - 1) : baseUrl;
    return Uri.parse('$base/api/v1/$path').replace(queryParameters: query);
  }

  Map<String, String> headers({String? idempotencyKey}) => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Authorization': ?(token == null ? null : 'Bearer $token'),
        'X-Hotel-Id': ?hotelId?.toString(),
        'Idempotency-Key': ?idempotencyKey,
      };

  Future<dynamic> get(String path, [Map<String, String>? query]) =>
      _send(() => _http.get(uri(path, query), headers: headers()));

  Future<dynamic> post(String path, [Map<String, dynamic>? body, String? idempotencyKey]) => _send(
      () => _http.post(uri(path), headers: headers(idempotencyKey: idempotencyKey), body: jsonEncode(body ?? {})));

  Future<dynamic> _send(Future<http.Response> Function() call) async {
    http.Response r;
    try {
      r = await call().timeout(const Duration(seconds: 15));
    } on SocketException catch (e) {
      throw ApiException(e.message, offline: true);
    } on http.ClientException catch (e) {
      throw ApiException(e.message, offline: true);
    } on HandshakeException catch (e) {
      throw ApiException(e.message, offline: true);
    } catch (e) {
      if (e.toString().contains('TimeoutException')) throw ApiException('timeout', offline: true);
      rethrow;
    }
    final body = r.body.isEmpty ? null : _decode(r.body);
    if (r.statusCode >= 200 && r.statusCode < 300) return body;
    String msg = 'HTTP ${r.statusCode}';
    if (body is Map) {
      msg = (body['detail'] ?? body['message'] ?? body['title'] ?? msg).toString();
    }
    throw ApiException(msg, status: r.statusCode);
  }

  static dynamic _decode(String s) {
    try {
      return jsonDecode(s);
    } catch (_) {
      return s;
    }
  }
}
