import { useState } from 'react';
import Sidebar from '@/Components/layout/Sidebar';
import Topbar from '@/Components/layout/Topbar';
import { cn } from '@/lib/utils';

interface Props {
    children: React.ReactNode;
    title?: string;
    subtitle?: string;
    actions?: React.ReactNode;
    noPadding?: boolean;
}

export default function AppLayout({ children, title, subtitle, actions, noPadding }: Props) {
    const [collapsed, setCollapsed] = useState(false);

    return (
        <div className="flex h-screen overflow-hidden bg-slate-100">
            <Sidebar collapsed={collapsed} />

            <div className="flex-1 flex flex-col overflow-hidden">
                <Topbar
                    title={title}
                    subtitle={subtitle}
                    collapsed={collapsed}
                    onToggle={() => setCollapsed(!collapsed)}
                    actions={actions}
                />

                <main className={cn(
                    'flex-1 overflow-y-auto',
                    !noPadding && 'p-6'
                )}>
                    {children}
                </main>
            </div>
        </div>
    );
}
