import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/app_state.dart';
import '../theme.dart';
import 'common.dart';
import 'guest.dart';
import 'staff.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(appStateProvider);
    switch (s.mode) {
      case AppMode.guest:
        return const GuestHome();
      case AppMode.staff:
        if (!s.signedIn) return const StaffLoginScreen();
        if (s.hotelId == null) return const HotelPickerScreen();
        return const StaffHome();
      case AppMode.none:
        return const ModeChooser();
    }
  }
}

void openSettings(BuildContext context) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const SettingsScreen()));

class SettingsAction extends StatelessWidget {
  const SettingsAction({super.key});
  @override
  Widget build(BuildContext context) =>
      IconButton(tooltip: t(context).settings, icon: const Icon(Icons.settings_outlined), onPressed: () => openSettings(context));
}

class ModeChooser extends ConsumerWidget {
  const ModeChooser({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = t(context);
    final s = ref.read(appStateProvider);
    Widget tile(IconData icon, String title, String desc, AppMode m) => Card(
          child: ListTile(
            contentPadding: const EdgeInsets.all(16),
            leading: Icon(icon, size: 36, color: navy),
            title: Text(title, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w600)),
            subtitle: Text(desc, style: const TextStyle(fontSize: 15)),
            onTap: () => s.setMode(m),
          ),
        );
    return Scaffold(
      appBar: AppBar(title: Text(l.appTitle), actions: const [SettingsAction()]),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Text(l.chooseMode, style: Theme.of(context).textTheme.titleLarge),
        const SizedBox(height: 16),
        tile(Icons.hotel_outlined, l.guest, l.guestModeDesc, AppMode.guest),
        tile(Icons.badge_outlined, l.staff, l.staffModeDesc, AppMode.staff),
      ]),
    );
  }
}

class SettingsScreen extends ConsumerStatefulWidget {
  const SettingsScreen({super.key});
  @override
  ConsumerState<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends ConsumerState<SettingsScreen> {
  late final _url = TextEditingController(text: ref.read(appStateProvider).baseUrl);

  @override
  Widget build(BuildContext context) {
    final l = t(context);
    final s = ref.watch(appStateProvider);
    return Scaffold(
      appBar: AppBar(title: Text(l.settings)),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        TextField(
          key: const Key('server-url'),
          controller: _url,
          keyboardType: TextInputType.url,
          decoration: InputDecoration(labelText: l.serverUrl, hintText: l.serverUrlHint),
        ),
        const SizedBox(height: 12),
        FilledButton(
          onPressed: () async {
            await s.setBaseUrl(_url.text);
            if (context.mounted) toast(context, l.saved);
          },
          child: Text(l.save),
        ),
        const SizedBox(height: 24),
        Text(l.language, style: Theme.of(context).textTheme.titleMedium),
        SegmentedButton<String>(
          segments: const [ButtonSegment(value: 'fr', label: Text('Français')), ButtonSegment(value: 'en', label: Text('English'))],
          selected: {s.locale.languageCode},
          onSelectionChanged: (v) => s.setLocale(v.first),
        ),
        const SizedBox(height: 24),
        OutlinedButton.icon(
          icon: const Icon(Icons.swap_horiz),
          label: Text('${l.guest} / ${l.staff}'),
          onPressed: () {
            s.setMode(AppMode.none);
            Navigator.of(context).pop();
          },
        ),
        if (s.signedIn) ...[
          const SizedBox(height: 12),
          OutlinedButton.icon(
            icon: const Icon(Icons.logout),
            label: Text(l.signOut),
            onPressed: () {
              s.logout();
              Navigator.of(context).pop();
            },
          ),
        ],
      ]),
    );
  }
}
