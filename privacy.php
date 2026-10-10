<?php
// privacy.php - Privacy notice for customers (Personal Data Protection Act 2010)
require_once 'config.php';

$settings = get_settings($pdo);
$campaign = $settings['campaign_title'];

public_page_start('Notis Privasi', 'Notis Privasi');
?>
<div class="card privacy">
    <p class="muted">Notis ini menerangkan bagaimana ASTECH Legacy menggunakan maklumat peribadi anda untuk <b><?php echo e($campaign); ?></b>, menurut Akta Perlindungan Data Peribadi 2010.</p>

    <h2>Maklumat yang kami kumpul</h2>
    <ul>
        <li>Nama penuh</li>
        <li>Nombor telefon bimbit</li>
        <li>Kod produk yang didaftarkan, serta tarikh dan masa penyertaan</li>
        <li>Alamat rangkaian (IP) peranti semasa mendaftar, untuk mengesan penyalahgunaan</li>
    </ul>

    <h2>Tujuan</h2>
    <ul>
        <li>Merekod penyertaan anda dan menjalankan cabutan bertuah</li>
        <li>Menghubungi anda melalui telefon atau WhatsApp berkaitan penyertaan dan hadiah</li>
        <li>Mengesahkan pemenang sebelum hadiah diserahkan</li>
    </ul>

    <h2>Perkongsian</h2>
    <p>Maklumat anda tidak dijual dan tidak dikongsi dengan pihak ketiga untuk tujuan pemasaran. Ia hanya digunakan oleh ASTECH Legacy bagi tujuan di atas.</p>

    <h2>Tempoh simpanan</h2>
    <p>Maklumat anda disimpan sepanjang tempoh kempen dan sehingga semua hadiah diserahkan, dan tidak disimpan lebih lama daripada yang diperlukan.</p>

    <h2>Hak anda</h2>
    <p>Anda boleh meminta untuk melihat atau membetulkan maklumat anda, atau menarik balik persetujuan anda (penyertaan anda akan dibatalkan), dengan menghubungi ASTECH Legacy.</p>

    <h2>Persetujuan</h2>
    <p>Pemberian maklumat adalah secara sukarela. Walau bagaimanapun, tanpa maklumat ini anda tidak dapat menyertai cabutan bertuah. Dengan menandakan kotak persetujuan semasa mendaftar, anda bersetuju dengan penggunaan maklumat seperti yang diterangkan di sini.</p>
</div>
<?php
public_actions([['Kembali ke laman utama', 'index.php', 'home']]);
public_page_end();
