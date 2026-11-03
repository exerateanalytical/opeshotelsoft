import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/api.dart';
import '../core/app_state.dart';
import '../core/offline_queue.dart';
import '../l10n/gen/app_localizations.dart';
import '../theme.dart';

AppLocalizations t(BuildContext c) => AppLocalizations.of(c);

void toast(BuildContext context, String msg) =>
    ScaffoldMessenger.of(context)..hideCurrentSnackBar()..showSnackBar(SnackBar(content: Text(msg, style: const TextStyle(fontSize: 16))));

String errorText(BuildContext c, Object e) => t(c).error(e is ApiException ? e.message : '$e');

/// Runs a staff mutation through the offline queue and tells the user
/// whether it went through or was queued.
Future<bool> runMutation(BuildContext context, WidgetRef ref, String path, String label, [Map<String, dynamic> body = const {}]) async {
  final l = t(context);
  try {
    final r = await ref.read(appStateProvider).mutate(path, label, body);
    if (context.mounted) toast(context, r == SubmitResult.sent ? l.done : l.queuedOffline);
    return true;
  } catch (e) {
    if (context.mounted) toast(context, errorText(context, e));
    return false;
  }
}

/// Gold badge shown when staff actions are waiting to sync.
class PendingSyncBanner extends ConsumerWidget {
  const PendingSyncBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(appStateProvider);
    if (s.pending == 0) return const SizedBox.shrink();
    return Container(
      key: const Key('pending-sync'),
      width: double.infinity,
      color: const Color(0xFFFFF4D6),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Row(children: [
        Badge(label: Text('${s.pending}'), backgroundColor: gold, textColor: navy, child: const Icon(Icons.cloud_upload_outlined, color: navy)),
        const SizedBox(width: 16),
        Expanded(child: Text(t(context).pendingSync(s.pending), style: const TextStyle(fontSize: 15, color: navy))),
        TextButton(
          onPressed: s.syncing
              ? null
              : () async {
                  final rejected = await s.sync();
                  if (context.mounted && rejected.isNotEmpty) toast(context, rejected.join('\n'));
                },
          child: Text(t(context).syncNow),
        ),
      ]),
    );
  }
}

/// Loads with [load], shows a spinner, an error with retry, or [builder].
class Loader<T> extends StatefulWidget {
  const Loader({super.key, required this.load, required this.builder});
  final Future<T> Function() load;
  final Widget Function(BuildContext, T, VoidCallback reload) builder;

  @override
  State<Loader<T>> createState() => _LoaderState<T>();
}

class _LoaderState<T> extends State<Loader<T>> {
  late Future<T> _f = widget.load();
  void _reload() => setState(() => _f = widget.load());

  @override
  Widget build(BuildContext context) => FutureBuilder<T>(
        future: _f,
        builder: (c, snap) {
          if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
          if (snap.hasError) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  Text(errorText(c, snap.error!), textAlign: TextAlign.center, style: const TextStyle(fontSize: 16)),
                  const SizedBox(height: 12),
                  OutlinedButton(onPressed: _reload, child: Text(t(c).retry)),
                ]),
              ),
            );
          }
          return RefreshIndicator(onRefresh: () async => _reload(), child: widget.builder(c, snap.data as T, _reload));
        },
      );
}

class EmptyText extends StatelessWidget {
  const EmptyText(this.text, {super.key});
  final String text;
  @override
  Widget build(BuildContext context) => ListView(children: [
        Padding(padding: const EdgeInsets.all(32), child: Text(text, textAlign: TextAlign.center, style: const TextStyle(fontSize: 17))),
      ]);
}

String isoDate(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

/// A tappable field that opens the date picker.
class DateField extends StatelessWidget {
  const DateField({super.key, required this.label, required this.value, required this.onChanged});
  final String label;
  final DateTime value;
  final ValueChanged<DateTime> onChanged;

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: () async {
          final now = DateTime.now();
          final d = await showDatePicker(context: context, initialDate: value, firstDate: DateTime(now.year - 1), lastDate: DateTime(now.year + 2));
          if (d != null) onChanged(d);
        },
        child: InputDecorator(decoration: InputDecoration(labelText: label), child: Text(isoDate(value), style: const TextStyle(fontSize: 17))),
      );
}
