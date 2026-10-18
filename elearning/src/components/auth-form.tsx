"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { CheckCircle2 } from "lucide-react";
import { Button } from "./ui/button";
import { Input, Label } from "./ui/input";

type Mode = "login" | "signup" | "reset";

const copy: Record<Mode, { title: string; subtitle: string; submit: string; done: string }> = {
  login: { title: "Connexion", subtitle: "Content de vous revoir.", submit: "Se connecter", done: "Connexion simulée : l'authentification sera branchée sur Supabase." },
  signup: { title: "Créer un compte", subtitle: "Gratuit, sans carte bancaire.", submit: "Créer mon compte", done: "Compte simulé : un email de confirmation sera envoyé une fois Supabase branché." },
  reset: { title: "Mot de passe oublié", subtitle: "Recevez un lien pour choisir un nouveau mot de passe.", submit: "Envoyer le lien", done: "Si un compte existe pour cet email, un lien de réinitialisation sera envoyé." },
};

export function AuthForm({ mode }: { mode: Mode }) {
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [done, setDone] = useState(false);
  const c = copy[mode];

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const data = new FormData(e.currentTarget);
    const next: Record<string, string> = {};
    const email = String(data.get("email") ?? "");
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) next.email = "Adresse email invalide.";
    if (mode === "signup" && String(data.get("name") ?? "").trim().length < 2) next.name = "Indiquez votre nom.";
    if (mode !== "reset" && String(data.get("password") ?? "").length < 8) next.password = "8 caractères minimum.";
    setErrors(next);
    if (Object.keys(next).length === 0) setDone(true);
  }

  if (done) {
    return (
      <div className="space-y-4 text-center">
        <CheckCircle2 className="mx-auto size-10 text-accent" aria-hidden />
        <p className="text-sm text-muted-foreground">{c.done}</p>
        <Link href="/dashboard" className="text-sm font-medium text-primary hover:underline">Voir l&apos;espace élève (démo)</Link>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      <div className="text-center">
        <h1 className="text-2xl font-bold">{c.title}</h1>
        <p className="mt-1 text-sm text-muted-foreground">{c.subtitle}</p>
      </div>
      {mode === "signup" && (
        <Field id="name" label="Nom complet" error={errors.name}>
          <Input id="name" name="name" autoComplete="name" aria-invalid={Boolean(errors.name)} />
        </Field>
      )}
      <Field id="email" label="Email" error={errors.email}>
        <Input id="email" name="email" type="email" autoComplete="email" aria-invalid={Boolean(errors.email)} />
      </Field>
      {mode !== "reset" && (
        <Field id="password" label="Mot de passe" error={errors.password}>
          <Input
            id="password"
            name="password"
            type="password"
            autoComplete={mode === "login" ? "current-password" : "new-password"}
            aria-invalid={Boolean(errors.password)}
          />
        </Field>
      )}
      {mode === "login" && (
        <div className="text-end">
          <Link href="/reset" className="text-sm text-primary hover:underline">Mot de passe oublié ?</Link>
        </div>
      )}
      <Button type="submit" size="lg" className="w-full">{c.submit}</Button>
      {mode !== "reset" && (
        <>
          <div className="flex items-center gap-3 text-xs text-muted-foreground">
            <span className="h-px flex-1 bg-border" /> ou <span className="h-px flex-1 bg-border" />
          </div>
          <Button type="button" variant="outline" size="lg" className="w-full" onClick={() => setDone(true)}>
            Continuer avec Google
          </Button>
        </>
      )}
      <p className="text-center text-sm text-muted-foreground">
        {mode === "login" ? (
          <>Pas encore de compte ? <Link href="/signup" className="font-medium text-primary hover:underline">Inscription</Link></>
        ) : (
          <>Déjà inscrit ? <Link href="/login" className="font-medium text-primary hover:underline">Connexion</Link></>
        )}
      </p>
    </form>
  );
}

function Field({ id, label, error, children }: { id: string; label: string; error?: string; children: React.ReactNode }) {
  return (
    <div>
      <Label htmlFor={id}>{label}</Label>
      {children}
      {error && <p className="mt-1 text-xs text-danger" role="alert">{error}</p>}
    </div>
  );
}
