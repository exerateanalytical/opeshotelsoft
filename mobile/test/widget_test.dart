import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:opes_hotel/core/api.dart';
import 'package:opes_hotel/core/app_state.dart';
import 'package:opes_hotel/core/offline_queue.dart';
import 'package:opes_hotel/l10n/gen/app_localizations.dart';
import 'package:opes_hotel/main.dart';
import 'package:opes_hotel/screens/common.dart';
import 'package:shared_preferences/shared_preferences.dart';

Future<AppState> makeState(Map<String, Object> prefs, [OfflineQueue? q]) async {
  SharedPreferences.setMockInitialValues(prefs);
  return AppState(await SharedPreferences.getInstance(), q ?? OfflineQueue(MemoryQueueStore()));
}

Widget wrap(AppState s) => ProviderScope(overrides: [appStateProvider.overrideWith((ref) => s)], child: const OpesApp());

void main() {
  testWidgets('starts in French with the mode chooser', (tester) async {
    await tester.pumpWidget(wrap(await makeState({})));
    await tester.pumpAndSettle();
    expect(find.text('Client'), findsOneWidget);
    expect(find.text('Personnel'), findsOneWidget);
  });

  testWidgets('guest loyalty card shows a QR code', (tester) async {
    await tester.pumpWidget(wrap(await makeState({'mode': 'guest', 'group_slug': 'opes', 'member_number': 'M-0042', 'locale': 'en'})));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Loyalty card'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('loyalty-qr')), findsOneWidget);
    expect(find.text('M-0042'), findsWidgets);
  });

  testWidgets('pending-sync badge shows queued actions', (tester) async {
    final q = OfflineQueue(MemoryQueueStore());
    await q.submit(path: 'hr/clock/in', label: 'in', send: (a) async => throw ApiException('down', offline: true));
    final s = await makeState({'locale': 'en'}, q);
    await s.refreshPending();
    await tester.pumpWidget(ProviderScope(
      overrides: [appStateProvider.overrideWith((ref) => s)],
      child: const MaterialApp(
        locale: Locale('en'),
        localizationsDelegates: AppLocalizations.localizationsDelegates,
        supportedLocales: AppLocalizations.supportedLocales,
        home: Scaffold(body: PendingSyncBanner()),
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('pending-sync')), findsOneWidget);
    expect(find.text('1'), findsOneWidget);
  });

  testWidgets('server url is editable in settings', (tester) async {
    final s = await makeState({'locale': 'en'});
    await tester.pumpWidget(wrap(s));
    await tester.pumpAndSettle();
    await tester.tap(find.byIcon(Icons.settings_outlined));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('server-url')), 'http://10.0.0.5:8000');
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();
    expect(s.baseUrl, 'http://10.0.0.5:8000');
  });
}

