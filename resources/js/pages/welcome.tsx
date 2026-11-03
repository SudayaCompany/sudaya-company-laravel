export default function Welcome({ app }: { app: { name: string; framework: string } }) {
    return (
        <main className="flex min-h-svh flex-col items-center justify-center gap-2 p-6 text-center">
            <h1 className="text-xl font-semibold">{app.name}</h1>
            <p className="text-sm text-neutral-500">Fondasi migrasi. {app.framework}.</p>
        </main>
    );
}
