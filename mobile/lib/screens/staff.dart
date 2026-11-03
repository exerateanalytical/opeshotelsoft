import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/app_state.dart';
import '../core/models.dart';
import '../theme.dart';
import 'common.dart';
import 'home.dart';

class StaffLoginScreen extends ConsumerStatefulWidget {
  const StaffLoginScreen({super.key});
  @override
  ConsumerState<StaffLoginScreen> createState() => _StaffLoginScreenState();
}

class _StaffLoginScreenState extends ConsumerState<StaffLoginScreen> {
  final _email = TextEditingController(), _pass = TextEditingController(), _mfa = TextEditingController();
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    final l = t(context);
    return Scaffold(
      appBar: AppBar(title: Text(l.staff), actions: const [SettingsAction()]),
      body: AutofillGroup(
        child: ListView(padding: const EdgeInsets.all(16), children: [
          TextField(controller: _email, keyboardType: TextInputType.emailAddress, autofillHints: const [AutofillHints.email], decoration: InputDecoration(labelText: l.email)),
          const SizedBox(height: 12),
          TextField(controller: _pass, obscureText: true, autofillHints: const [AutofillHints.password], decoration: InputDecoration(labelText: l.password)),
          const SizedBox(height: 12),
          TextField(controller: _mfa, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: l.mfaCode)),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _busy
                ? null
                : () async {
                    setState(() => _busy = true);
                    try {
                      await ref.read(appStateProvider).login(_email.text.trim(), _pass.text, mfa: _mfa.text.trim());
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(context, e));
                    } finally {
                      if (mounted) setState(() => _busy = false);
                    }
                  },
            child: Text(l.signIn),
          ),
        ]),
      ),
    );
  }
}

class HotelPickerScreen extends ConsumerWidget {
  const HotelPickerScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(appStateProvider);
    final l = t(context);
    return Scaffold(
      appBar: AppBar(title: Text(l.chooseHotel), actions: const [SettingsAction()]),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        for (final h in s.user?.hotels ?? <HotelRef>[])
          Card(
            child: ListTile(
              contentPadding: const EdgeInsets.all(12),
              leading: const Icon(Icons.apartment, color: navy),
              title: Text(h.name, style: const TextStyle(fontSize: 18)),
              subtitle: Text('${h.code} · ${h.city}'),
              selected: h.id == s.hotelId,
              onTap: () => s.setHotel(h.id),
            ),
          ),
      ]),
    );
  }
}

class StaffHome extends ConsumerStatefulWidget {
  const StaffHome({super.key});
  @override
  ConsumerState<StaffHome> createState() => _StaffHomeState();
}

class _StaffHomeState extends ConsumerState<StaffHome> with WidgetsBindingObserver {
  int _tab = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) => ref.read(appStateProvider).sync());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  // Back in the foreground: try to flush the offline queue.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) ref.read(appStateProvider).sync();
  }

  @override
  Widget build(BuildContext context) {
    final l = t(context);
    final s = ref.watch(appStateProvider);
    final hotel = s.user?.hotels.where((h) => h.id == s.hotelId).firstOrNull;
    final pages = <(IconData, String, Widget)>[
      (Icons.cleaning_services_outlined, l.housekeeping, const HousekeepingTasksScreen()),
      (Icons.meeting_room_outlined, l.roomStatus, const RoomStatusScreen()),
      (Icons.build_outlined, l.workOrders, const WorkOrdersScreen()),
      (Icons.more_horiz, l.payslips, const MeScreen()),
    ];
    return Scaffold(
      appBar: AppBar(
        title: Text(hotel?.name ?? l.staff),
        actions: [
          if ((s.user?.hotels.length ?? 0) > 1)
            IconButton(tooltip: l.chooseHotel, icon: const Icon(Icons.apartment), onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const HotelPickerScreen()))),
          const SettingsAction(),
        ],
      ),
      body: Column(children: [const PendingSyncBanner(), Expanded(child: pages[_tab].$3)]),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) => setState(() => _tab = i),
        destinations: [
          for (final p in pages)
            NavigationDestination(
              icon: p.$1 == Icons.more_horiz && s.pending > 0 ? Badge(label: Text('${s.pending}'), backgroundColor: gold, textColor: navy, child: Icon(p.$1)) : Icon(p.$1),
              label: p.$2,
            ),
        ],
      ),
    );
  }
}

class HousekeepingTasksScreen extends ConsumerWidget {
  const HousekeepingTasksScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = t(context);
    return Loader<List<HkTask>>(
      load: () async {
        final r = await ref.read(appStateProvider).api.get('housekeeping/tasks', {'mine': '1'});
        return (r as List).map((e) => HkTask.fromJson(Map<String, dynamic>.from(e))).toList();
      },
      builder: (c, tasks, reload) => tasks.isEmpty
          ? EmptyText(l.noTasks)
          : ListView(padding: const EdgeInsets.all(12), children: [
              for (final task in tasks)
                Card(
                  child: ListTile(
                    contentPadding: const EdgeInsets.all(12),
                    title: Text(l.roomNumber(task.roomNumber), style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w600)),
                    subtitle: Text('${task.kind} · ${task.status}', style: const TextStyle(fontSize: 15)),
                    trailing: switch (task.status) {
                      'pending' => FilledButton(
                          onPressed: () async {
                            if (await runMutation(c, ref, 'housekeeping/tasks/${task.id}/start', '${l.start} ${task.roomNumber}')) reload();
                          },
                          child: Text(l.start)),
                      'in_progress' => FilledButton(
                          onPressed: () async {
                            if (await runMutation(c, ref, 'housekeeping/tasks/${task.id}/finish', '${l.finish} ${task.roomNumber}')) reload();
                          },
                          child: Text(l.finish)),
                      _ => null,
                    },
                  ),
                ),
            ]),
    );
  }
}

const roomStatuses = ['vacant_clean', 'vacant_dirty', 'occupied_clean', 'occupied_dirty', 'inspected', 'out_of_order', 'out_of_service'];

class RoomStatusScreen extends ConsumerWidget {
  const RoomStatusScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = t(context);
    return Loader<List<Map<String, dynamic>>>(
      load: () async {
        final r = await ref.read(appStateProvider).api.get('rooms', {'per_page': '200'});
        final list = r is Map ? (r['data'] as List? ?? []) : (r as List);
        return list.map((e) => Map<String, dynamic>.from(e)).toList();
      },
      builder: (c, rooms, reload) => ListView(padding: const EdgeInsets.all(12), children: [
        for (final room in rooms)
          Card(
            child: ListTile(
              contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
              title: Text(l.roomNumber('${room['number']}'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
              subtitle: Text('${room['hk_status'] ?? ''}', style: const TextStyle(fontSize: 15)),
              trailing: PopupMenuButton<String>(
                tooltip: l.newStatus,
                icon: const Icon(Icons.edit_outlined, color: navy),
                onSelected: (st) async {
                  if (await runMutation(c, ref, 'housekeeping/rooms/${room['id']}/status', '${room['number']} → $st', {'status': st})) reload();
                },
                itemBuilder: (_) => [for (final st in roomStatuses) PopupMenuItem(value: st, child: Text(st, style: const TextStyle(fontSize: 16)))],
              ),
            ),
          ),
      ]),
    );
  }
}

class WorkOrdersScreen extends ConsumerWidget {
  const WorkOrdersScreen({super.key});

  Future<void> _complete(BuildContext c, WidgetRef ref, WorkOrder w, VoidCallback reload) async {
    final l = t(c);
    final ctl = TextEditingController();
    final text = await showDialog<String>(
      context: c,
      builder: (d) => AlertDialog(
        title: Text('${l.complete} ${w.number}'),
        content: TextField(controller: ctl, maxLines: 3, autofocus: true, decoration: InputDecoration(labelText: l.resolution)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(d), child: Text(l.cancel)),
          FilledButton(onPressed: () => Navigator.pop(d, ctl.text.trim()), child: Text(l.complete)),
        ],
      ),
    );
    if (text == null || text.isEmpty || !c.mounted) return;
    if (await runMutation(c, ref, 'maintenance/work-orders/${w.id}/complete', '${l.complete} ${w.number}', {'resolution': text})) reload();
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = t(context);
    return Loader<List<WorkOrder>>(
      load: () async {
        final s = ref.read(appStateProvider);
        return WorkOrder.listFrom(await s.api.get('maintenance/work-orders', {'assigned_to': '${s.user!.id}'}));
      },
      builder: (c, orders, reload) {
        final open = orders.where((w) => !['completed', 'cancelled', 'closed'].contains(w.status)).toList();
        if (open.isEmpty) return EmptyText(l.noWorkOrders);
        return ListView(padding: const EdgeInsets.all(12), children: [
          for (final w in open)
            Card(
              child: ListTile(
                contentPadding: const EdgeInsets.all(12),
                title: Text('${w.number} · ${w.title}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
                subtitle: Text('${w.roomNumber.isEmpty ? '' : '${l.roomNumber(w.roomNumber)} · '}${w.priority} · ${w.status}', style: const TextStyle(fontSize: 15)),
                trailing: switch (w.status) {
                  'open' || 'assigned' => FilledButton(
                      onPressed: () async {
                        if (await runMutation(c, ref, 'maintenance/work-orders/${w.id}/start', '${l.start} ${w.number}')) reload();
                      },
                      child: Text(l.start)),
                  'in_progress' => FilledButton(onPressed: () => _complete(c, ref, w, reload), child: Text(l.complete)),
                  _ => null,
                },
              ),
            ),
        ]);
      },
    );
  }
}

/// Payslips, leave and clock, grouped under one tab.
class MeScreen extends StatelessWidget {
  const MeScreen({super.key});
  @override
  Widget build(BuildContext context) {
    final l = t(context);
    return DefaultTabController(
      length: 3,
      child: Column(children: [
        TabBar(labelStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600), tabs: [Tab(text: l.clock), Tab(text: l.leave), Tab(text: l.payslips)]),
        const Expanded(child: TabBarView(children: [ClockScreen(), LeaveScreen(), PayslipsScreen()])),
      ]),
    );
  }
}

class ClockScreen extends ConsumerWidget {
  const ClockScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = t(context);
    return Loader<Map<String, dynamic>>(
      load: () async {
        try {
          return Map<String, dynamic>.from(await ref.read(appStateProvider).api.get('hr/clock/status'));
        } catch (_) {
          return <String, dynamic>{'unknown': true};
        }
      },
      builder: (c, st, reload) {
        final inNow = st['clocked_in'] == true;
        return ListView(padding: const EdgeInsets.all(16), children: [
          if (st['unknown'] != true)
            Text(inNow ? l.clockedInSince('${st['since'] ?? ''}'.replaceFirst('T', ' ').split('.').first) : l.notClockedIn, style: const TextStyle(fontSize: 19)),
          const SizedBox(height: 16),
          FilledButton.icon(
            icon: const Icon(Icons.login),
            label: Text(l.clockIn),
            onPressed: inNow ? null : () async {
              if (await runMutation(c, ref, 'hr/clock/in', l.clockIn)) reload();
            },
          ),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            icon: const Icon(Icons.logout),
            label: Text(l.clockOut),
            onPressed: st['unknown'] != true && !inNow ? null : () async {
              if (await runMutation(c, ref, 'hr/clock/out', l.clockOut)) reload();
            },
          ),
        ]);
      },
    );
  }
}

class LeaveScreen extends ConsumerStatefulWidget {
  const LeaveScreen({super.key});
  @override
  ConsumerState<LeaveScreen> createState() => _LeaveScreenState();
}

class _LeaveScreenState extends ConsumerState<LeaveScreen> {
  int? _type;
  DateTime _start = DateTime.now(), _end = DateTime.now();
  final _reason = TextEditingController();

  @override
  Widget build(BuildContext context) {
    final l = t(context);
    return Loader<List<LeaveType>>(
      load: () async {
        final r = await ref.read(appStateProvider).api.get('hr/leave-types');
        final list = r is Map ? (r['data'] as List? ?? []) : (r as List);
        return list.map((e) => LeaveType.fromJson(Map<String, dynamic>.from(e))).toList();
      },
      builder: (c, types, reload) => ListView(padding: const EdgeInsets.all(16), children: [
        DropdownButtonFormField<int>(
          initialValue: _type,
          decoration: InputDecoration(labelText: l.leaveType),
          items: [for (final ty in types) DropdownMenuItem(value: ty.id, child: Text(ty.name))],
          onChanged: (v) => setState(() => _type = v),
        ),
        const SizedBox(height: 12),
        DateField(label: l.startDate, value: _start, onChanged: (d) => setState(() {
              _start = d;
              if (_end.isBefore(d)) _end = d;
            })),
        const SizedBox(height: 12),
        DateField(label: l.endDate, value: _end, onChanged: (d) => setState(() => _end = d.isBefore(_start) ? _start : d)),
        const SizedBox(height: 12),
        TextField(controller: _reason, maxLines: 2, decoration: InputDecoration(labelText: l.reason)),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _type == null
              ? null
              : () async {
                  final ok = await runMutation(c, ref, 'hr/leave-requests', '${l.leave} ${isoDate(_start)}', {
                    'leave_type_id': _type,
                    'start_date': isoDate(_start),
                    'end_date': isoDate(_end),
                    if (_reason.text.trim().isNotEmpty) 'reason': _reason.text.trim(),
                  });
                  if (ok) _reason.clear();
                },
          child: Text(l.submit),
        ),
      ]),
    );
  }
}

class PayslipsScreen extends ConsumerWidget {
  const PayslipsScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = t(context);
    return Loader<List<Payslip>>(
      load: () async {
        final r = await ref.read(appStateProvider).api.get('payroll/payslips/mine');
        return (r as List).map((e) => Payslip.fromJson(Map<String, dynamic>.from(e))).toList();
      },
      builder: (c, slips, reload) => slips.isEmpty
          ? EmptyText(l.noPayslips)
          : ListView(padding: const EdgeInsets.all(12), children: [
              for (final p in slips)
                Card(
                  child: ListTile(
                    contentPadding: const EdgeInsets.all(12),
                    title: Text(p.period, style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w600)),
                    subtitle: Text('${l.gross}: ${formatFcfa(p.gross)}', style: const TextStyle(fontSize: 15)),
                    trailing: Text('${l.net}\n${formatFcfa(p.net)}', textAlign: TextAlign.right, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700, color: navy)),
                  ),
                ),
            ]),
    );
  }
}
