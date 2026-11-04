import {
    LayoutDashboard, CalendarDays, DoorOpen, Sparkles, CreditCard,
    ShoppingCart, BarChart3, Building2, Users, Wrench,
    Hotel, User, Layers, Package, Network, UsersRound,
    FileText, Wallet, Receipt, ShieldCheck, Code2, Coins, History, Circle,
    ChartGantt, ListOrdered, Briefcase, Sun, BedDouble, Smartphone, Settings2, Mail, Inbox, GitFork, CalendarClock,
    BellRing, MessageCircle, TrendingUp, SlidersHorizontal, Tag, ArrowUpCircle, Gauge, Building, ShieldAlert, QrCode, GitMerge, Banknote, PiggyBank, ClipboardCheck, ChefHat, ScanLine, CalendarRange, GraduationCap, FileSignature, ClipboardList, UserCircle, Rocket, Stamp, ServerCog,
} from 'lucide-react';

/** One nav entry, filtered server-side by the user's permissions (app/Http/Navigation.php). */
export interface NavEntry {
    label: string;
    path: string;
    icon: string;
    section: string | null;
    permission: string[] | null;
}

/** Icon names usable in app/Http/Navigation.php. Import and add a lucide icon here to use a new one. */
const ICONS: Record<string, React.ElementType> = {
    LayoutDashboard, CalendarDays, DoorOpen, Sparkles, CreditCard, ShoppingCart, BarChart3, Building2, Users, Wrench,
    Hotel, User, Layers, Package, Network, UsersRound, FileText, Wallet, Receipt, ShieldCheck, Code2, Coins, History,
    ChartGantt, ListOrdered, Briefcase, Sun, BedDouble, Smartphone, Settings2, Mail, Inbox, GitFork, CalendarClock,
    BellRing, MessageCircle, TrendingUp, SlidersHorizontal, Tag, ArrowUpCircle, Gauge, Building, ShieldAlert, QrCode, GitMerge, Banknote, PiggyBank, ClipboardCheck, ChefHat, ScanLine, CalendarRange, GraduationCap, FileSignature, ClipboardList, UserCircle, Rocket, Stamp, ServerCog,
};

export const navIcon = (name: string): React.ElementType => ICONS[name] ?? Circle;

/** Active when the URL is the path or below it; the longest matching path wins so /reservations does not light up on /reservations/tape-chart. */
export function activePath(url: string, nav: NavEntry[]): string | null {
    const clean = url.split('?')[0].split('#')[0];
    let best: string | null = null;
    for (const n of nav) {
        if (clean === n.path || clean.startsWith(n.path + '/')) {
            if (!best || n.path.length > best.length) best = n.path;
        }
    }
    return best;
}

export function groupNav(nav: NavEntry[]) {
    const sections: { title: string | null; items: NavEntry[] }[] = [];
    for (const item of nav) {
        const last = sections[sections.length - 1];
        if (last && last.title === item.section) last.items.push(item);
        else sections.push({ title: item.section, items: [item] });
    }
    return sections;
}

/** Destinations the phone tab bar prefers, in order. The first four the user may see are used, topped up from the menu order. */
const TAB_PRIORITY = ['/dashboard', '/front-desk', '/reservations', '/housekeeping', '/pos', '/service-requests', '/stock', '/finance', '/billing/folios', '/hr', '/me', '/owner'];

/** Floor roles land on the task home (/tasks) first; their own workplace page follows. */
const FLOOR_ROLES = ['room-attendant', 'maintenance-technician', 'pos-cashier', 'storekeeper', 'fnb-manager', 'housekeeping-manager'];
const FLOOR_TAB_PRIORITY = ['/tasks', '/housekeeping', '/maintenance', '/pos', '/stock', '/service-requests', '/me'];

export function pickTabs(nav: NavEntry[], count = 4, roles: string[] = []): NavEntry[] {
    const picked: NavEntry[] = [];
    const floor = roles.some((r) => FLOOR_ROLES.includes(r));
    for (const p of floor ? FLOOR_TAB_PRIORITY : TAB_PRIORITY) {
        const hit = nav.find((n) => n.path === p);
        if (hit && picked.length < count) picked.push(hit);
    }
    for (const n of nav) if (picked.length < count && !picked.includes(n)) picked.push(n);
    return picked;
}
