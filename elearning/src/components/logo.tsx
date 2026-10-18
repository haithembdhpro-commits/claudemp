import Link from "next/link";
import { GraduationCap } from "lucide-react";

export function Logo({ href = "/" }: { href?: string }) {
  return (
    <Link href={href} className="flex items-center gap-2 font-bold text-primary">
      <span className="grid size-9 place-items-center rounded-xl bg-primary text-primary-foreground">
        <GraduationCap className="size-5" aria-hidden />
      </span>
      <span className="text-lg tracking-tight">Tradeversity</span>
    </Link>
  );
}
