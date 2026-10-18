import type { Metadata, Viewport } from "next";
import { Inter } from "next/font/google";
import "./globals.css";

const inter = Inter({ variable: "--font-inter", subsets: ["latin"] });

export const metadata: Metadata = {
  title: { default: "Tradeversity — Formations de trading", template: "%s · Tradeversity" },
  description: "Apprenez le trading pas à pas : vidéos, quiz et certificats. Paiement CIB, Edahabia et carte internationale.",
  openGraph: { siteName: "Tradeversity", locale: "fr_FR", type: "website" },
};

export const viewport: Viewport = { themeColor: "#0b3b5c" };

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="fr" dir="ltr" className={`${inter.variable} h-full antialiased`}>
      <body className="flex min-h-full flex-col font-sans">{children}</body>
    </html>
  );
}
