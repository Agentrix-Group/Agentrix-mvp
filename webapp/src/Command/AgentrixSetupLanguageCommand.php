<?php declare(strict_types=1);

namespace App\Command;

use App\Entity\Executable;
use App\Entity\Language;
use App\Service\DOMJudgeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ZipArchive;

#[AsCommand(
    name: 'agentrix:setup-language',
    description: 'Registers the Agentrix ZIP language and Sanity Check compile script in DOMjudge'
)]
class AgentrixSetupLanguageCommand extends Command
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly DOMJudgeService $dj
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Agentrix Bot Language & Sanity Check Setup');

        $buildScript = <<<'BASH'
#!/bin/sh
# DOMjudge executable builder: makes 'run' executable
chmod +x run
exit 0
BASH;

        $runScript = <<<'BASH'
#!/bin/bash
# Agentrix Bot Sanity Check Script
# Called by compile.sh with: <dest> <memlimit> <source_file>...
DEST="$1"
MEMLIMIT="$2"
SOURCE="$3"

echo "=== AGENTRIX BOT SANITY CHECK ==="
echo "Validating bot package: $(basename "$SOURCE")"

UNPACK_DIR="unpacked_bot"
mkdir -p "$UNPACK_DIR"

if ! unzip -q -o "$SOURCE" -d "$UNPACK_DIR"; then
    echo "ERROR: El archivo enviado no es un archivo .zip válido o está corrupto." >&2
    exit 1
fi

if [ ! -f "$UNPACK_DIR/run.sh" ]; then
    echo "ERROR: No se encontró 'run.sh' en la raíz del archivo .zip." >&2
    echo "Archivos encontrados:" >&2
    ls -la "$UNPACK_DIR" >&2
    exit 1
fi

chmod +x "$UNPACK_DIR/run.sh"

echo "Probando arranque del bot (INIT handshake)..."
cd "$UNPACK_DIR"
INIT_OUTPUT=$(echo '{"phase":"INIT"}' | timeout 5s ./run.sh 2>&1)
RET=$?
cd ..

if [ $RET -ne 0 ]; then
    echo "ERROR: El bot terminó con código de error $RET durante la inicialización." >&2
    echo "Salida obtenida:" >&2
    echo "$INIT_OUTPUT" >&2
    exit 1
fi

if ! echo "$INIT_OUTPUT" | grep -q '"READY"'; then
    echo "ERROR: El bot no respondió con {\"status\":\"READY\"} al mensaje {\"phase\":\"INIT\"}." >&2
    echo "Salida obtenida:" >&2
    echo "$INIT_OUTPUT" >&2
    exit 1
fi

echo "✔ Sanity Check superado con éxito. El bot respondió READY."

cat << 'EOF' > "$DEST"
#!/bin/sh
echo '{"status":"READY"}'
exit 0
EOF
chmod +x "$DEST"
exit 0
BASH;

        // Build temporary ZIP file containing both 'build' and 'run'
        $tempZipPath = tempnam(sys_get_temp_dir(), 'agentrix_zip_');
        $zip = new ZipArchive();
        if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $io->error("No se pudo crear el archivo zip temporal en $tempZipPath");
            return Command::FAILURE;
        }

        $zip->addFromString('build', $buildScript);
        $zip->setExternalAttributesName('build', ZipArchive::OPSYS_UNIX, (0100755) << 16);

        $zip->addFromString('run', $runScript);
        $zip->setExternalAttributesName('run', ZipArchive::OPSYS_UNIX, (0100755) << 16);

        $zip->close();

        // Reopen with DOMJudgeService to parse ImmutableExecutable
        $zipToRead = new ZipArchive();
        $zipToRead->open($tempZipPath);
        $immutable = $this->dj->createImmutableExecutable($zipToRead);
        $zipToRead->close();
        @unlink($tempZipPath);

        // Find or create Executable 'zip'
        $executableRepo = $this->em->getRepository(Executable::class);
        $executable = $executableRepo->find('zip');
        if (!$executable) {
            $executable = new Executable();
            $executable->setExecid('zip');
            $executable->setType('compile');
            $this->em->persist($executable);
            $io->text('Creando nuevo ejecutable: zip');
        } else {
            $io->text('Actualizando ejecutable existente: zip');
        }

        $executable->setDescription('Agentrix Bot Sanity Check Script');
        $executable->setImmutableExecutable($immutable);

        // Find or create Language 'zip'
        $languageRepo = $this->em->getRepository(Language::class);
        $language = $languageRepo->find('zip');
        if (!$language) {
            $language = new Language();
            $language->setLangid('zip');
            $this->em->persist($language);
            $io->text('Creando nuevo lenguaje: zip');
        } else {
            $io->text('Actualizando lenguaje existente: zip');
        }

        $language->setName('Agentrix Bot Package');
        $language->setExternalid('zip');
        $language->setExtensions(['zip']);
        $language->setAllowSubmit(true);
        $language->setAllowJudge(true);
        $language->setTimeFactor(1.0);
        $language->setCompileExecutable($executable);

        $this->em->flush();

        $io->success('Lenguaje "zip" (Agentrix Bot Package) y ejecutable de Sanity Check configurados exitosamente.');
        return Command::SUCCESS;
    }
}
