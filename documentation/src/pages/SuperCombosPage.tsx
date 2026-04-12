import { CodeBlock } from '@/components/CodeBlock';

export function SuperCombosPage() {
  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-3xl font-bold tracking-tight">Super Combos</h1>
        <p className="text-muted-foreground mt-2">
          One-liners and short bash scripts that combine moosh commands to accomplish
          common Moodle administration tasks.
        </p>
      </div>

      <section className="space-y-4">
        <h2 className="text-xl font-semibold">Upload and configure site logos</h2>
        <p className="text-muted-foreground">
          Moodle stores logos in the <code className="bg-muted px-1.5 py-0.5 rounded text-sm">core_admin</code> component
          under the <code className="bg-muted px-1.5 py-0.5 rounded text-sm">logo</code> and{' '}
          <code className="bg-muted px-1.5 py-0.5 rounded text-sm">logocompact</code> file areas.
          Uploading the files alone is not enough &mdash; you must also set the corresponding
          config values so that Moodle knows to use them.
        </p>
        <p className="text-muted-foreground">
          The system context ID is always <code className="bg-muted px-1.5 py-0.5 rounded text-sm">1</code> in
          Moodle. Both logos use item ID <code className="bg-muted px-1.5 py-0.5 rounded text-sm">0</code> and
          stored path <code className="bg-muted px-1.5 py-0.5 rounded text-sm">/</code>.
        </p>

        <CodeBlock>{`# Upload the main logo
moosh file:upload logo.png \\
  --contextid=1 --component=core_admin --filearea=logo --run

# Upload the compact logo (shown in the navbar)
moosh file:upload compact-logo.png \\
  --contextid=1 --component=core_admin --filearea=logocompact --run

# Tell Moodle to use the uploaded files
moosh config:set --plugin=core_admin logo /logo.png --run
moosh config:set --plugin=core_admin logocompact /compact-logo.png --run

# Clear the theme cache so the new logos appear immediately
moosh cache:purge --run`}</CodeBlock>

        <p className="text-muted-foreground">
          The config value must match the filename prefixed with{' '}
          <code className="bg-muted px-1.5 py-0.5 rounded text-sm">/</code>.
          Without the <code className="bg-muted px-1.5 py-0.5 rounded text-sm">config:set</code> step,
          the files will appear in the Appearance settings form but won&apos;t render in the theme.
        </p>
      </section>

      <section className="space-y-4">
        <h2 className="text-xl font-semibold">Archive all files for a course</h2>
        <p className="text-muted-foreground">
          Pipe <code className="bg-muted px-1.5 py-0.5 rounded text-sm">file:list</code> into{' '}
          <code className="bg-muted px-1.5 py-0.5 rounded text-sm">file:info</code> to
          get the physical paths of all files belonging to a course, then feed them
          to <code className="bg-muted px-1.5 py-0.5 rounded text-sm">tar</code>.
        </p>

        <CodeBlock>{`# Create a tar.gz archive of all files in course 2
moosh file:list --courseid 2 -i \\
  | moosh file:info --stdin --field path \\
  | tar czf course-files.tar.gz -T -`}</CodeBlock>
      </section>
    </div>
  );
}
