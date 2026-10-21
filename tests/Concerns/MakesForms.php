<?php

namespace Tests\Concerns;

use App\Models\Department;
use App\Models\DynamicForm;
use App\Models\FormQuestion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** Builders for the dynamic-forms tests: users in departments, forms with questions, and upload fixtures. */
trait MakesForms
{
    protected function makeUser(string $role, ?Department $department = null): User
    {
        $user = User::factory()->create(['role' => $role]);
        if ($department) {
            $user->departments()->attach($department->id);
        }

        return $user;
    }

    protected function makeDepartment(string $name): Department
    {
        return Department::create(['name' => $name, 'name_ar' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(4))]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $questions  each: label, type, [options], [is_required]
     */
    protected function makeForm(array $attributes = [], array $questions = [], ?User $creator = null): DynamicForm
    {
        $form = DynamicForm::create(array_merge([
            'title'      => 'نموذج '.Str::random(4),
            'slug'       => 'form-'.Str::lower(Str::random(8)),
            'type'       => 'general',
            'status'     => 'active',
            'created_by' => ($creator ?? User::factory()->create(['role' => 'admin']))->id,
        ], $attributes));

        foreach (array_values($questions) as $i => $q) {
            FormQuestion::create(array_merge(['form_id' => $form->id, 'is_required' => false, 'sort_order' => $i + 1], $q));
        }

        return $form->load('questions');
    }

    protected function pdfUpload(string $name = 'doc.pdf', int $kb = 4): UploadedFile
    {
        $body = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n";
        $body .= '%'.str_repeat('x', max(0, $kb * 1024 - strlen($body) - 8))."\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $body);
    }

    protected function pngUpload(string $name = 'pic.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    /** A genuine .docx container (a ZIP with the Office Open XML parts). */
    protected function docxUpload(string $name = 'letter.docx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    protected function phpUpload(string $name = 'shell.php'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, '<?php echo "pwned"; ?>');
    }
}
