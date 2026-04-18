import { Link } from 'react-router-dom';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { CodeBlock } from '@/components/CodeBlock';
import { Terminal, Eye, BookOpen, FlaskConical, ShieldCheck, History } from 'lucide-react';
import { commands } from '@/data/commands';
import { categories } from '@/data/categories';

const features = [
  {
    icon: Terminal,
    title: 'Nearly 200 Commands',
    description: 'CLI tools for managing everything Moodle - courses, users, plugins, roles, and more.',
  },
  {
    icon: Eye,
    title: 'One moosh to rule them all',
    description: 'Single moosh installation will handle all your Moodle instances on a server.',
  },
  {
    icon: BookOpen,
    title: 'Open Source forever',
    description: 'Licensed under GNU GPL v3+. Community-driven development.',
  },
  {
    icon: FlaskConical,
    title: 'Well tested',
    description: 'Covered by nearly 3000 tests!',
  },
  {
    icon: ShieldCheck,
    title: 'Powerful but gentle',
    description: 'moosh is extremely powerful - but it was also designed to prevent you from destroying your own site.',
  },
  {
    icon: History,
    title: 'Support for old Moodle and PHP versions',
    description: (
      <>
        moosh 2 built to run with the latest Moodle and PHP versions.
        But... if there is a command that you absolutely need in your old Moodle, then{' '}
        <a className="underline" href="https://github.com/tmuras/moosh/issues">request it</a> as a{' '}
        <a
          href="https://github.com/tmuras/moosh/tree/2.x/stand_alone_scripts"
          className="underline"
          target="_blank"
          rel="noreferrer"
        >
          stand-alone script
        </a>.
      </>
    ),
  },
];

export function HomePage() {
  return (
    <div className="space-y-12">
      <section className="text-center space-y-4 py-8">
        <div className="flex justify-center">
          <Badge variant="secondary" className="text-sm">
            {commands.length} commands &middot; {categories.length} categories
          </Badge>
        </div>
        <h1 className="text-4xl font-bold tracking-tight sm:text-5xl">
          moosh2
        </h1>
        <p className="text-xl text-muted-foreground max-w-2xl mx-auto">
          Moodle Shell &mdash; manage you Moodle like a hacker - from command-line!
        </p>
        <p className="text-base text-muted-foreground max-w-2xl mx-auto">
          This is moosh 2 - a rewrite for Moodle 5.2 and above.
        </p></section>

      <section>
        <h2 className="text-lg font-semibold mb-3">Quick Start</h2>
        <CodeBlock>{`# Install
composer install

# Run a command
php moosh.php course:list --moodle-path=/path/to/moodle

# Output as JSON
php moosh.php course:list -o json

# Get help for any command
php moosh.php help course:list`}</CodeBlock>
      </section>

      <section>
        <h2 className="text-lg font-semibold mb-4">Features</h2>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {features.map((feature) => (
            <Card key={feature.title}>
              <CardHeader className="pb-2">
                <feature.icon className="h-5 w-5 text-muted-foreground mb-1" />
                <CardTitle className="text-base">{feature.title}</CardTitle>
              </CardHeader>
              <CardContent>
                <CardDescription>{feature.description}</CardDescription>
              </CardContent>
            </Card>
          ))}
        </div>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-semibold mb-3">What is it for?</h2>
        <p>moosh is meant to be run:</p>
        <ul className="list-disc pl-6 space-y-1">
          <li>As a CLI command to support Moodle development, troubleshooting or administration</li>
          <li>In bash-oneliners for quick task automation</li>
          <li>As part of shell scripts to automate Moodle processes</li>
        </ul>
        <p>There is no moosh API that could be used programmatically.</p>
      </section>

      <section>
        <h2 className="text-lg font-semibold mb-4">Command Categories</h2>
        <div className="flex flex-wrap gap-2">
          {categories.map((cat) => {
            const count = commands.filter((c) => c.category === cat.slug).length;
            return (
              <Link key={cat.slug} to={`/commands?category=${cat.slug}`}>
                <Badge variant="outline" className="cursor-pointer hover:bg-accent text-sm">
                  {cat.label}
                  <span className="ml-1 text-muted-foreground">({count})</span>
                </Badge>
              </Link>
            );
          })}
        </div>
      </section>
    </div>
  );
}
