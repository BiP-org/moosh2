import { Link } from 'react-router-dom';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { CodeBlock } from '@/components/CodeBlock';
import { Terminal, Eye, BookOpen } from 'lucide-react';
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
