import { Fragment, type ReactNode } from 'react';
import { useParams, Link } from 'react-router-dom';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbSeparator, BreadcrumbPage } from '@/components/ui/breadcrumb';
import { CodeBlock } from '@/components/CodeBlock';
import { commands } from '@/data/commands';
import { categories } from '@/data/categories';

/**
 * Render help-text as ReactNodes, turning URLs into clickable links.
 * Whitespace (including newlines) is preserved by the surrounding
 * `whitespace-pre-wrap` class on the wrapping element.
 */
function renderHelp(text: string): ReactNode[] {
  const parts = text.split(/(https?:\/\/\S+)/g);
  return parts.map((part, i) => {
    if (!/^https?:\/\//.test(part)) {
      return <Fragment key={i}>{part}</Fragment>;
    }
    // Strip a single trailing punctuation character so end-of-sentence
    // periods, commas etc. don't get sucked into the URL.
    const m = part.match(/^(.*?)([.,;:!?)\]]?)$/);
    const url = m ? m[1] : part;
    const trailing = m ? m[2] : '';
    return (
      <Fragment key={i}>
        <a
          href={url}
          target="_blank"
          rel="noopener noreferrer"
          className="text-primary underline break-all"
        >
          {url}
        </a>
        {trailing}
      </Fragment>
    );
  });
}

export function CommandDetailPage() {
  const { category, command } = useParams<{ category: string; command: string }>();
  const cmd = commands.find((c) => c.category === category && c.name === `${category}:${command}`);
  const cat = categories.find((c) => c.slug === category);

  if (!cmd) {
    return (
      <div className="text-center py-12">
        <h1 className="text-2xl font-bold">Command not found</h1>
        <p className="text-muted-foreground mt-2">
          The command <code className="bg-muted px-1.5 py-0.5 rounded">{category}:{command}</code> does not exist.
        </p>
        <Link to="/commands" className="text-primary underline mt-4 inline-block">
          Browse all commands
        </Link>
      </div>
    );
  }

  return (
    <div className="space-y-8">
      <Breadcrumb>
        <BreadcrumbList>
          <BreadcrumbItem>
            <BreadcrumbLink render={<Link to="/commands" />}>
              Commands
            </BreadcrumbLink>
          </BreadcrumbItem>
          <BreadcrumbSeparator />
          <BreadcrumbItem>
            <BreadcrumbLink render={<Link to={`/commands?category=${category}`} />}>
              {cat?.label || category}
            </BreadcrumbLink>
          </BreadcrumbItem>
          <BreadcrumbSeparator />
          <BreadcrumbItem>
            <BreadcrumbPage>{cmd.name}</BreadcrumbPage>
          </BreadcrumbItem>
        </BreadcrumbList>
      </Breadcrumb>

      <div>
        <h1 className="text-3xl font-bold tracking-tight font-mono">{cmd.name}</h1>
        <p className="text-muted-foreground mt-2 text-lg">{cmd.description}</p>
        <div className="flex gap-2 mt-3">
          <Badge variant="secondary">{cmd.category}</Badge>
          <Badge variant="outline">Bootstrap: {cmd.bootstrapLevel}</Badge>
          {cmd.sinceVersion && <Badge>Since Moodle {cmd.sinceVersion}</Badge>}
        </div>
      </div>

      {cmd.help && (
        <section className="space-y-2">
          <h2 className="text-xl font-semibold">Description</h2>
          <p className="text-muted-foreground whitespace-pre-wrap">{renderHelp(cmd.help)}</p>
        </section>
      )}

      {cmd.arguments.length > 0 && (
        <section className="space-y-3">
          <h2 className="text-xl font-semibold">Arguments</h2>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>Required</TableHead>
                <TableHead>Array</TableHead>
                <TableHead>Description</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {cmd.arguments.map((arg) => (
                <TableRow key={arg.name}>
                  <TableCell className="font-mono text-sm">{arg.name}</TableCell>
                  <TableCell>
                    <Badge variant={arg.required ? 'default' : 'secondary'} className="text-xs">
                      {arg.required ? 'required' : 'optional'}
                    </Badge>
                  </TableCell>
                  <TableCell>
                    {arg.isArray && <Badge variant="outline" className="text-xs">array</Badge>}
                  </TableCell>
                  <TableCell className="text-muted-foreground">{arg.description}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </section>
      )}

      {cmd.options.length > 0 && (
        <section className="space-y-3">
          <h2 className="text-xl font-semibold">Options</h2>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Option</TableHead>
                <TableHead>Shortcut</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Default</TableHead>
                <TableHead>Description</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {cmd.options.map((opt) => (
                <TableRow key={opt.name}>
                  <TableCell className="font-mono text-sm whitespace-nowrap">{opt.name}</TableCell>
                  <TableCell className="font-mono text-sm">
                    {opt.shortcut || <span className="text-muted-foreground">&mdash;</span>}
                  </TableCell>
                  <TableCell>
                    <Badge variant={opt.type === 'value_none' ? 'secondary' : 'outline'} className="text-xs">
                      {opt.type === 'value_none' ? 'flag' : opt.type === 'value_optional' ? 'optional' : 'value'}
                    </Badge>
                  </TableCell>
                  <TableCell className="font-mono text-sm">
                    {opt.default || <span className="text-muted-foreground">&mdash;</span>}
                  </TableCell>
                  <TableCell className="text-muted-foreground">{opt.description}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </section>
      )}

      {cmd.examples && cmd.examples.length > 0 && (
        <section className="space-y-3">
          <h2 className="text-xl font-semibold">Examples</h2>
          {cmd.examples.map((ex, i) => (
            <div key={i} className="space-y-1">
              {typeof ex === 'object' && ex.description && (
                <p className="text-sm text-muted-foreground">{ex.description}</p>
              )}
              <CodeBlock>{typeof ex === 'string' ? ex : ex.command}</CodeBlock>
            </div>
          ))}
        </section>
      )}
    </div>
  );
}
