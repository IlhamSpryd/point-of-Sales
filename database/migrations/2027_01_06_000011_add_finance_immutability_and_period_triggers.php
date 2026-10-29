<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // 1. trg_journal_entries_before_update
        DB::unprepared("
            CREATE TRIGGER trg_journal_entries_before_update BEFORE UPDATE ON journal_entries
            FOR EACH ROW
            BEGIN
                IF OLD.status IN ('POSTED', 'REVERSED') THEN
                    -- Izinkan transisi status dari POSTED ke REVERSED saat reverseJournal()
                    IF OLD.status = 'POSTED' AND NEW.status = 'REVERSED' THEN
                        IF OLD.total_debit <> NEW.total_debit
                           OR OLD.total_credit <> NEW.total_credit
                           OR OLD.posting_date <> NEW.posting_date
                           OR OLD.accounting_period_id <> NEW.accounting_period_id
                           OR OLD.tenant_id <> NEW.tenant_id
                           OR OLD.journal_number <> NEW.journal_number THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Modifikasi nilai finansial jurnal POSTED dilarang saat reversal.';
                        END IF;
                    ELSE
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Modifikasi jurnal POSTED/REVERSED dilarang. Gunakan jurnal pembalik (reversal).';
                    END IF;
                END IF;
            END;
        ");

        // 2. trg_journal_entries_before_delete
        DB::unprepared("
            CREATE TRIGGER trg_journal_entries_before_delete BEFORE DELETE ON journal_entries
            FOR EACH ROW
            BEGIN
                IF OLD.status IN ('POSTED', 'REVERSED') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Penghapusan jurnal POSTED/REVERSED dilarang keras.';
                END IF;
            END;
        ");

        // 3. trg_journal_lines_before_update
        DB::unprepared("
            CREATE TRIGGER trg_journal_lines_before_update BEFORE UPDATE ON journal_lines
            FOR EACH ROW
            BEGIN
                DECLARE parent_status VARCHAR(30);
                SELECT status INTO parent_status FROM journal_entries WHERE id = OLD.journal_entry_id;
                IF parent_status IN ('POSTED', 'REVERSED') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Baris jurnal yang sudah POSTED tidak dapat diubah.';
                END IF;
            END;
        ");

        // 4. trg_journal_lines_before_delete
        DB::unprepared("
            CREATE TRIGGER trg_journal_lines_before_delete BEFORE DELETE ON journal_lines
            FOR EACH ROW
            BEGIN
                DECLARE parent_status VARCHAR(30);
                SELECT status INTO parent_status FROM journal_entries WHERE id = OLD.journal_entry_id;
                IF parent_status IN ('POSTED', 'REVERSED') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Baris jurnal yang sudah POSTED tidak dapat dihapus.';
                END IF;
            END;
        ");

        // 5. trg_journal_entries_before_insert
        DB::unprepared("
            CREATE TRIGGER trg_journal_entries_before_insert BEFORE INSERT ON journal_entries
            FOR EACH ROW
            BEGIN
                DECLARE period_status VARCHAR(20);
                SELECT status INTO period_status FROM accounting_periods WHERE id = NEW.accounting_period_id;
                IF period_status = 'LOCKED' OR (period_status = 'CLOSED' AND NEW.journal_type <> 'CLOSING_ENTRY') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Posting jurnal ke periode akuntansi CLOSED atau LOCKED ditolak.';
                END IF;
            END;
        ");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_entries_before_insert;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_lines_before_delete;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_lines_before_update;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_entries_before_delete;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_entries_before_update;');
    }
};
