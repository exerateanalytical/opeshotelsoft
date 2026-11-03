import { useRef, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

export interface TabItem { key: string; label: string; icon?: React.ElementType; badge?: ReactNode }

interface Props {
    tabs: TabItem[];
    value: string;
    onChange: (key: string) => void;
    label: string;
    className?: string;
}

/** Tab list that scrolls sideways on phones, with arrow-key navigation. Render the panel yourself with role="tabpanel" and id panel-KEY. */
export default function Tabs({ tabs, value, onChange, label, className }: Props) {
    const list = useRef<HTMLDivElement>(null);
    const move = (e: React.KeyboardEvent, i: number) => {
        const next = e.key === 'ArrowRight' ? (i + 1) % tabs.length : e.key === 'ArrowLeft' ? (i - 1 + tabs.length) % tabs.length : e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : -1;
        if (next < 0) return;
        e.preventDefault();
        onChange(tabs[next].key);
        list.current?.querySelectorAll<HTMLElement>('[role=tab]')[next]?.focus();
    };
    return (
        <div ref={list} role="tablist" aria-label={label} className={cn('tabs', className)}>
            {tabs.map((tab, i) => {
                const Icon = tab.icon;
                const on = tab.key === value;
                return (
                    <button key={tab.key} type="button" role="tab" id={`tab-${tab.key}`} aria-selected={on} aria-controls={`panel-${tab.key}`} tabIndex={on ? 0 : -1}
                        className="tab" onClick={() => onChange(tab.key)} onKeyDown={(e) => move(e, i)}>
                        {Icon && <Icon className="h-4 w-4" aria-hidden="true" />}
                        {tab.label}
                        {tab.badge}
                    </button>
                );
            })}
        </div>
    );
}
