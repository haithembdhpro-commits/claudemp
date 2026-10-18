import { CirclePlay } from "lucide-react";

// Zone de lecture de démonstration.
// En production : iframe Bunny Stream avec une URL signée renvoyée par /api/video/[lessonId].
export function VideoPlayer({ title }: { title: string }) {
  return (
    <div className="relative aspect-video w-full overflow-hidden rounded-xl bg-slate-900 text-white">
      <div className="absolute inset-0 bg-gradient-to-br from-primary/80 to-slate-900" aria-hidden />
      <div className="relative flex h-full flex-col items-center justify-center gap-3 p-6 text-center">
        <CirclePlay className="size-16 opacity-90" aria-hidden />
        <p className="max-w-md text-sm text-white/80">{title}</p>
        <p className="text-xs text-white/50">Vidéo de démonstration — lecteur Bunny Stream à brancher</p>
      </div>
    </div>
  );
}
