import Link from "next/link";
import { BookOpen, ChartColumn, CreditCard, Users } from "lucide-react";
import { Logo } from "@/components/logo";

const nav = [
  { href: "/admin", label: "Vue d'ensemble", icon: ChartColumn },
  { href: "/admin#cours", label: "Formations", icon: BookOpen },
  { href: "/admin#ventes", label: "Ventes", icon: CreditCard },
  { href: "/admin#eleves", label: "Élèves", icon: Users },
];

export default function AdminLayout({ children }: LayoutProps<"/admin">) {
  return (
    <div className="flex flex-1 flex-col lg:flex-row">
      <aside className="border-b border-border bg-card lg:w-64 lg:shrink-0 lg:border-e lg:border-b-0">
        <div className="flex h-16 items-center px-5">
          <Logo href="/admin" />
        </div>
        <nav className="flex gap-1 overflow-x-auto px-3 pb-3 lg:flex-col lg:pb-0" aria-label="Administration">
          {nav.map(({ href, label, icon: Icon }) => (
            <Link key={href} href={href} className="flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium hover:bg-muted">
              <Icon className="size-4" aria-hidden /> {label}
            </Link>
          ))}
        </nav>
      </aside>
      <main className="min-w-0 flex-1">{children}</main>
    </div>
  );
}
