import Link from "next/link";
import { BookOpen, LayoutDashboard } from "lucide-react";
import { getCurrentUser } from "@/lib/data";
import { Logo } from "./logo";
import { Container } from "./ui/container";

export function AppHeader() {
  const user = getCurrentUser();
  const initials = user.fullName.split(" ").map((p) => p[0]).join("").slice(0, 2);

  return (
    <header className="sticky top-0 z-40 border-b border-border bg-card">
      <Container className="flex h-16 max-w-7xl items-center justify-between gap-4">
        <Logo href="/dashboard" />
        <nav className="flex items-center gap-1" aria-label="Espace élève">
          <Link href="/dashboard" className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium hover:bg-muted">
            <LayoutDashboard className="size-4" aria-hidden /> <span className="hidden sm:inline">Mes cours</span>
          </Link>
          <Link href="/courses" className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium hover:bg-muted">
            <BookOpen className="size-4" aria-hidden /> <span className="hidden sm:inline">Catalogue</span>
          </Link>
          <span className="ms-2 grid size-9 place-items-center rounded-full bg-primary text-sm font-semibold text-primary-foreground" title={user.fullName}>
            {initials}
          </span>
        </nav>
      </Container>
    </header>
  );
}
