---
name: supabase-rls
description: Workflow Supabase pour la plateforme e-learning — migrations SQL versionnées, Row Level Security, fonctions is_enrolled/is_admin, tests RLS, génération des types. À charger avant de créer ou modifier une table, une policy, une fonction SQL ou une Edge Function.
---

# Supabase — migrations et RLS

## Règles absolues
1. **RLS activé sur toutes les tables** (`alter table ... enable row level security;`) dans la même migration que le `create table`.
2. Aucune policy `using (true)` sur une table contenant des données payantes ou personnelles.
3. `service_role` uniquement côté serveur (route handlers, Edge Functions). Jamais dans un composant client ni une variable `NEXT_PUBLIC_*`.
4. Toute modification de schéma passe par une migration : `npx supabase migration new <nom>` puis `npx supabase db push` (jamais d'édition à la main dans le dashboard prod).
5. Après chaque migration : `npx supabase gen types typescript --linked > elearning/src/lib/database.types.ts`.

## Fonctions de base
```sql
create or replace function public.is_admin() returns boolean
language sql stable security definer set search_path = '' as $$
  select exists (select 1 from public.profiles p where p.id = auth.uid() and p.role = 'admin');
$$;

create or replace function public.is_enrolled(p_course_id uuid) returns boolean
language sql stable security definer set search_path = '' as $$
  select exists (
    select 1 from public.enrollments e
    where e.user_id = auth.uid() and e.course_id = p_course_id
      and (e.expires_at is null or e.expires_at > now())
  );
$$;
```

## Modèles de policies
- `courses` / `modules` : lecture publique si `published`, écriture `is_admin()`.
- `lessons` : métadonnées publiques ; la colonne vidéo est servie par une route serveur qui vérifie `is_enrolled()` (ou une vue `lessons_secure`).
- `enrollments`, `payments` : `select` où `user_id = auth.uid()` ; **aucun** `insert/update` client — écrits seulement par le webhook (service_role).
- `lesson_progress` : `select/insert/update` où `user_id = auth.uid()`.

## Tests RLS (à écrire pour chaque table sensible)
- Utilisateur anonyme → 0 ligne sur `enrollments`, `payments`, colonnes vidéo.
- Utilisateur non inscrit → pas d'accès à la vidéo d'une leçon non `is_preview`.
- Utilisateur A ne voit jamais les lignes de B.
- Tentative d'`insert` dans `enrollments` depuis le client → refusée.

## Performance
- Index sur chaque clé étrangère et sur `(user_id, course_id)`, `(user_id, lesson_id)`.
- Vercel → Postgres via le pooler Supavisor (mode transaction, port 6543).
- Lancer les advisors (MCP Supabase `get_advisors`) après chaque migration : sécurité + performance.
