import type { Metadata } from "next";
import { AuthForm } from "@/components/auth-form";

export const metadata: Metadata = { title: "Inscription" };

export default function Page() {
  return <AuthForm mode="signup" />;
}
