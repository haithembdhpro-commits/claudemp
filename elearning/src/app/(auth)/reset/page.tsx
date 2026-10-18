import type { Metadata } from "next";
import { AuthForm } from "@/components/auth-form";

export const metadata: Metadata = { title: "Mot de passe oublié" };

export default function Page() {
  return <AuthForm mode="reset" />;
}
