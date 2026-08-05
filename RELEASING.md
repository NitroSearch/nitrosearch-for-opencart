# Releasing

This module is distributed as a **GitHub Release carrying two installable archives**. OpenCart has
no central directory to publish to — a merchant downloads the archive for their major and uploads it
in their back office under **Extensions → Installer**.

Versions follow [SemVer](https://semver.org/).

## Why two archives

OpenCart maintains **two majors concurrently** — 3.0.5.0 was released eight months *after* 4.1.0.3 —
and they are not compatible with each other. A controller's directory, class name, namespace and
base class all differ, one is typed and one is not, and the two installers disagree about where an
archive's contents belong. There is no single archive both accept.

Everything carrying logic is shared and copied into both, verbatim. Only the files whose shape the
framework dictates are written twice.

## Cutting a release

1. **Bump the version** in `adapters/oc4/install.json`. It is the single source — the build reads it
   for both archives, and it is what OpenCart 4 shows in its installed-extensions list.
2. **Move the `## [Unreleased]` entries** in `CHANGELOG.md` into a dated section for the new version,
   and update the compare links at the bottom.
3. **Build:**

   ```bash
   ./bin/build-module.sh
   ```

   Writes both archives to `dist/` and prints their contents. Read that listing — the archive
   layouts are not the repository layout, and getting one wrong fails silently rather than loudly.

4. **Install each built archive into a shop that has never seen the module.** Not a copy, not a bind
   mount, not a hand-placed file. This is where packaging fails, and no test covers it: a working
   copy already sitting in place has put the files where they belong, so it cannot show you a
   mistake. A hand-placed file once "proved" a route that a real install could never have served,
   and the claim had to be withdrawn.

   On OpenCart 4, remember that **installing the module is a second step** after installing the
   archive — the storefront half does not answer until it is done.

5. **Tag and publish**, attaching **both** archives:

   ```bash
   git tag -a v1.0.0 -m "v1.0.0"
   git push origin main --follow-tags
   gh release create v1.0.0 --title "v1.0.0" --notes-from-tag \
     dist/nitrosearch.ocmod.zip dist/nitrosearch-oc3-1.0.0.ocmod.zip
   ```

   Run `gh release create` **inside this repo** — combined with `--repo` and `--notes-from-tag` it is
   an invalid flag pair, and it fails without publishing while the tag push still looks green.
   Verify with `gh release list`, never with a checkmark.

## The one filename that is not a filename

**`nitrosearch.ocmod.zip` must keep exactly that name, permanently.** OpenCart 4 takes the
extension's *code* from `basename($file, '.ocmod.zip')`, ignoring the `name` inside `install.json`,
and that code decides two things at once: the directory the files are installed into, and the PHP
namespace registered for them. A version in the filename yields a code that is not a legal namespace
segment, and nothing resolves — with no error that points at the cause.

So the OpenCart 4 archive is named for the code and nothing else. The version lives in
`install.json`, where OpenCart reads it. The OpenCart 3 archive carries its version in the filename,
because there the filename means nothing.

The same trap applies to a merchant who renames their download. `README.md` warns them, because the
module cannot see it from the inside.
