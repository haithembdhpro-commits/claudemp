"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useState } from "react";
import { Menu, X } from "lucide-react";
import { Logo } from "./logo";
import { ButtonLink } from "./ui/button";
import { Container } from "./ui/container";
import { cn } from "@/lib/utils";

const nav = [
  { href: "/courses", label: "Formations" },
  { href: "/#avantages", label: "Pourquoi nous" },
  { href: "/#faq", label: "FAQ" },
];

export function SiteHeader() {
  const [open, setOpen] = useState(false);
  const pathname = usePathname();

  return (
    <header className="sticky top-0 z-40 border-b border-border bg-card/90 backdrop-blur">
      <Container className="flex h-16 items-center justify-between gap-4">
        <Logo />
        <nav className="hidden items-center gap-6 md:flex" aria-label="Navigation principale">
          {nav.map((item) => (
            <Link
              key={item.href}
              href={item.href}
              className={cn(
                "text-sm font-medium text-muted-foreground hover:text-foreground",
                pathname === item.href && "text-foreground",
              )}
            >
              {item.label}
            </Link>
          ))}
        </nav>
        <div className="hidden items-center gap-2 md:flex">
          <ButtonLink href="/login" variant="ghost" size="sm">
            Connexion
          </ButtonLink>
          <ButtonLink href="/signup" size="sm">
            Commencer
          </ButtonLink>
        </div>
        <button
          type="button"
          className="grid size-10 place-items-center rounded-xl hover:bg-muted md:hidden"
          aria-label={open ? "Fermer le menu" : "Ouvrir le menu"}
          aria-expanded={open}
          onClick={() => setOpen((v) => !v)}
        >
          {open ? <X className="size-5" /> : <Menu className="size-5" />}
        </button>
      </Container>
      {open && (
        <div className="border-t border-border bg-card md:hidden">
          <Container className="flex flex-col gap-1 py-3">
            {nav.map((item) => (
              <Link
                key={item.href}
                href={item.href}
                onClick={() => setOpen(false)}
                className="rounded-lg px-3 py-2.5 text-sm font-medium hover:bg-muted"
              >
                {item.label}
              </Link>
            ))}
            <div className="mt-2 grid grid-cols-2 gap-2">
              <ButtonLink href="/login" variant="outline" onClick={() => setOpen(false)}>
                Connexion
              </ButtonLink>
              <ButtonLink href="/signup" onClick={() => setOpen(false)}>
                Commencer
              </ButtonLink>
            </div>
          </Container>
        </div>
      )}
    </header>
  );
}
