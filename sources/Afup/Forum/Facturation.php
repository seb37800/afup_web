<?php
namespace Afup\Site\Forum;
use Afup\Site\Utils\Logs;
use Afup\Site\Utils\Mail;
use Afup\Site\Utils\Pays;
use Afup\Site\Utils\PDF_Facture;
use AppBundle\Email\Mailer\Attachment;
use AppBundle\Email\Mailer\MailUser;
use AppBundle\Email\Mailer\MailUserFactory;
use AppBundle\Email\Mailer\Message;
use AppBundle\Event\Model\Invoice;
use AppBundle\Event\Model\Ticket;

class Facturation
{
    /**
     * Instance de la couche d'abstraction à la base de données
     * @var     object
     * @access  private
     */
    var $_bdd;

    /**
     * Constructeur.
     *
     * @param  object $bdd Instance de la couche d'abstraction à la base de données
     * @access public
     * @return void
     */
    function __construct(&$bdd)
    {
        $this->_bdd = $bdd;
    }

    /**
     * Renvoit les informations concernant une inscription
     *
     * @param  string $reference Reference de la facturation
     * @param  string $champs Champs à renvoyer
     * @access public
     * @return array
     */
    function obtenir($reference, $champs = '*')
    {
        $requete = 'SELECT';
        $requete .= '  ' . $champs . ' ';
        $requete .= 'FROM';
        $requete .= '  afup_facturation_forum ';
        $requete .= 'WHERE reference=' . $this->_bdd->echapper($reference);
        return $this->_bdd->obtenirEnregistrement($requete);
    }

    /**
     * Renvoit la liste des inscriptions à facturer ou facturé au forum
     *
     * @param  int $id_forum Id du forum
     * @param  string $champs Champs à renvoyer
     * @param  string $ordre Tri des enregistrements
     * @param  bool $associatif Renvoyer un tableau associatif ?
     * @access public
     * @return array
     */
    function obtenirListe($id_forum = null,
                          $champs = '*',
                          $ordre = 'date_reglement',
                          $associatif = false,
                          $filtre = false)
    {
        $requete = 'SELECT';
        $requete .= '  ' . $champs . ' ';
        $requete .= 'FROM';
        $requete .= '  afup_facturation_forum ';
        $requete .= 'WHERE etat IN ( ' . AFUP_FORUM_ETAT_REGLE . ', ' . AFUP_FORUM_ETAT_ATTENTE_REGLEMENT . ', ' . AFUP_FORUM_ETAT_CONFIRME . ') ';
        $requete .= '  AND id_forum =' . $id_forum . ' ';
        if ($filtre) {
            $requete .= '  AND (societe LIKE \'%' . $filtre . '%\' OR reference LIKE \'%' . $filtre . '%\' ) ';
        }
        $requete .= 'ORDER BY ' . $ordre;

        if ($associatif) {
            return $this->_bdd->obtenirAssociatif($requete);
        } else {
            return $this->_bdd->obtenirTous($requete);
        }
    }

    function creerReference($id_forum, $label)
    {
        $label = preg_replace('/[^A-Z0-9_\-\:\.;]/', '', strtoupper(supprimerAccents($label)));

        return 'F' . date('Y') . sprintf('%02d', $id_forum) . '-' . date('dm') . '-' . substr($label, 0, 5) . '-' . substr(md5(date('r') . $label), -5);
    }

    function estFacture($reference)
    {
        $facture = $this->obtenir($reference, 'etat, facturation');
        if ($facture['facturation'] == AFUP_FORUM_FACTURE_A_ENVOYER) {
            $requete = 'UPDATE afup_inscription_forum ';
            $requete .= 'SET facturation=' . AFUP_FORUM_FACTURE_ENVOYEE . ' ';
            $requete .= 'WHERE reference=' . $this->_bdd->echapper($reference);
            $this->_bdd->executer($requete);

            $requete = 'UPDATE afup_facturation_forum ';
            $requete .= 'SET facturation=' . AFUP_FORUM_FACTURE_ENVOYEE . ', date_facture= ' . time() . ' ';
            $requete .= 'WHERE reference=' . $this->_bdd->echapper($reference);
            return $this->_bdd->executer($requete);
        }
        return true;
    }

    function genererDevis($reference, $chemin = null)
    {
        $requete = 'SELECT aff.*, af.titre AS event_name
        FROM afup_facturation_forum aff
        LEFT JOIN afup_forum af ON af.id = aff.id_forum
        WHERE reference=' . $this->_bdd->echapper($reference);
        $facture = $this->_bdd->obtenirEnregistrement($requete);

        $requete = 'SELECT aif.*, aft.pretty_name
        FROM afup_inscription_forum aif
        LEFT JOIN afup_forum_tarif aft ON aft.id = aif.type_inscription
        WHERE reference=' . $this->_bdd->echapper($reference);
        $inscriptions = $this->_bdd->obtenirTous($requete);


        $configuration = $GLOBALS['AFUP_CONF'];

        $pays = new Pays($this->_bdd);

        // Construction du PDF

        $pdf = new PDF_Facture($configuration);
        $pdf->AddPage();

        $pdf->Cell(130, 5);
        $pdf->Cell(60, 5, 'Le ' . date('d/m/Y', (isset($facture['date_facture']) && !empty($facture['date_facture']) ? $facture['date_facture'] : time())));

        $pdf->Ln();
        $pdf->Ln();
        $pdf->Ln();

        if (empty($facture['societe'])) {
            $facture['societe'] = $facture['nom'] . " " . $facture['prenom'];
        }

        // A l'attention du client [adresse]
        $pdf->SetFont('Arial', 'BU', 10);
        $pdf->Cell(130, 5, utf8_decode('Objet : Devis n°' . $reference));
        $pdf->SetFont('Arial', '', 10);
        $pdf->Ln(10);
        $pdf->MultiCell(130, 5, utf8_decode($facture['societe']) . "\n" . utf8_decode($facture['adresse']) . "\n" . utf8_decode($facture['code_postal']) . "\n" . utf8_decode($facture['ville']) . "\n" . utf8_decode($pays->obtenirNom($facture['id_pays'])));

        $pdf->Ln(15);

        $pdf->MultiCell(180, 5, utf8_decode(sprintf("Devis concernant votre participation au %s organisé par l'Association Française des Utilisateurs de PHP (AFUP).", $facture['event_name'])));
        // Cadre
        $pdf->Ln(10);
        $pdf->SetFillColor(200, 200, 200);
        $pdf->Cell(50, 5, 'Type', 1, 0, 'L', 1);
        $pdf->Cell(100, 5, 'Personne inscrite', 1, 0, 'L', 1);
        $pdf->Cell(40, 5, 'Prix', 1, 0, 'L', 1);

        $total = 0;
        foreach ($inscriptions as $inscription) {
            $pdf->Ln();
            $pdf->SetFillColor(255, 255, 255);

            $pdf->Cell(50, 5, $this->truncate(utf8_decode($inscription['pretty_name']), 27), 1);
            $pdf->Cell(100, 5, utf8_decode($inscription['prenom']) . ' ' . utf8_decode($inscription['nom']), 1);
            $pdf->Cell(40, 5, utf8_decode($inscription['montant']) . utf8_decode(' '), 1);
            $total += $inscription['montant'];
        }

        $pdf->Ln();
        $pdf->SetFillColor(225, 225, 225);
        $pdf->Cell(150, 5, 'TOTAL', 1, 0, 'L', 1);
        $pdf->Cell(40, 5, $total . utf8_decode(' '), 1, 0, 'L', 1);

        $pdf->Ln(15);
        $pdf->Cell(10, 5, 'TVA non applicable - art. 293B du CGI');

        if (is_null($chemin)) {
            $pdf->Output('Devis - ' . ($facture['societe'] ? $facture['societe'] : $facture['nom'] . '_' . $facture['prenom']) . ' - ' . date('Y-m-d_H-i', $facture['date_facture']) . '.pdf', 'D');
        } else {
            $pdf->Output($chemin, 'F');
        }
    }

    protected function truncate($value, $length)
    {
        if ($value <= $length) {
            return $value;
        }

        return substr($value, 0, $length) . '...';
    }

    /**
     * Génère une facture au format PDF
     *
     * @param string $reference Reference de la facture
     * @param string $chemin Chemin du fichier PDF à générer. Si ce chemin est omi, le PDF est renvoyé au navigateur.
     * @access public
     * @return bool
     */
    function genererFacture($reference, $chemin = null)
    {
        $requete = 'SELECT aff.*, af.titre AS event_name
        FROM afup_facturation_forum aff
        LEFT JOIN afup_forum af ON af.id = aff.id_forum
        WHERE reference=' . $this->_bdd->echapper($reference);
        $facture = $this->_bdd->obtenirEnregistrement($requete);

        $requete = 'SELECT aif.*, aft.pretty_name
        FROM afup_inscription_forum aif
        LEFT JOIN afup_forum_tarif aft ON aft.id = aif.type_inscription
        WHERE reference=' . $this->_bdd->echapper($reference);
        $inscriptions = $this->_bdd->obtenirTous($requete);

        $configuration = $GLOBALS['AFUP_CONF'];

        $pays = new Pays($this->_bdd);

        // Construction du PDF

        $pdf = new PDF_Facture($configuration);
        $pdf->AddPage();

        $pdf->Cell(130, 5);
        $pdf->Cell(60, 5, 'Le ' . date('d/m/Y', (isset($facture['date_facture']) && !empty($facture['date_facture']) ? $facture['date_facture'] : time())));

        $pdf->Ln();
        $pdf->Ln();
        $pdf->Ln();

        if (empty($facture['societe'])) {
            $facture['societe'] = $facture['nom'] . " " . $facture['prenom'];
        }

        // A l'attention du client [adresse]
        $pdf->SetFont('Arial', 'BU', 10);
        $pdf->Cell(130, 5, utf8_decode('Objet : Facture n°' . $reference));
        $pdf->SetFont('Arial', '', 10);
        $pdf->Ln(10);
        $pdf->MultiCell(130, 5, utf8_decode($facture['societe']) . "\n" . utf8_decode($facture['adresse']) . "\n" . utf8_decode($facture['code_postal']) . "\n" . utf8_decode($facture['ville']) . "\n" . utf8_decode($pays->obtenirNom($facture['id_pays'])));

        $pdf->Ln(15);

        $pdf->MultiCell(180, 5, utf8_decode(sprintf("Facture concernant votre participation au %s organisé par l'Association Française des Utilisateurs de PHP (AFUP).", $facture['event_name'])));

        if ($facture['informations_reglement']) {
            $pdf->Ln(10);
            $pdf->Cell(32, 5, utf8_decode('Référence client : '));
            $infos = explode("\n", $facture['informations_reglement']);
            foreach ($infos as $info) {
                $pdf->Cell(100, 5, utf8_decode($info));
                $pdf->Ln();
                $pdf->Cell(32, 5);
            }
        }

        // Cadre
        $pdf->Ln(10);
        $pdf->SetFillColor(200, 200, 200);
        $pdf->Cell(50, 5, 'Type', 1, 0, 'L', 1);
        $pdf->Cell(100, 5, 'Personne inscrite', 1, 0, 'L', 1);
        $pdf->Cell(40, 5, 'Prix', 1, 0, 'L', 1);

        $total = 0;
        foreach ($inscriptions as $inscription) {
            $pdf->Ln();
            $pdf->SetFillColor(255, 255, 255);

            $pdf->Cell(50, 5, $this->truncate(utf8_decode($inscription['pretty_name']), 27), 1);
            $pdf->Cell(100, 5, utf8_decode($inscription['prenom']) . ' ' . utf8_decode($inscription['nom']), 1);
            $pdf->Cell(40, 5, utf8_decode($inscription['montant']) . utf8_decode(' '), 1);
            $total += $inscription['montant'];
        }

        if ($facture['type_reglement'] == 1) { // Paiement par chèque
            $pdf->Ln();
            $pdf->Cell(50, 5, 'FRAIS', 1);
            $pdf->Cell(100, 5, utf8_decode('Paiement par chèque'), 1);
            $pdf->Cell(40, 5, '25' . utf8_decode(' '), 1);
            $total += 25;
        }

        $pdf->Ln();
        $pdf->SetFillColor(225, 225, 225);
        $pdf->Cell(150, 5, 'TOTAL', 1, 0, 'L', 1);
        $pdf->Cell(40, 5, $total . utf8_decode(' '), 1, 0, 'L', 1);

        $pdf->Ln(15);
        if ($facture['etat'] == 4) {
            switch ($facture['type_reglement']) {
                case 0:
                    $type = 'par CB';
                    break;
                case 1:
                    $type = 'par chèque';
                    break;
                case 2:
                    $type = 'par virement';
                    break;
            }
            $pdf->SetTextColor(255, 0, 0);
            $pdf->Cell(130, 5);
            if ($facture['type_reglement'] != Ticket::PAYMENT_NONE) {
                $pdf->Cell(60, 5, utf8_decode('Payé ' . $type . ' le ' . date('d/m/Y', $facture['date_reglement'])));
            }
            $pdf->SetTextColor(0, 0, 0);
        }
        $pdf->Ln();
        $pdf->Cell(10, 5, 'TVA non applicable - art. 293B du CGI');

        if (is_null($chemin)) {
            $pdf->Output('Facture - ' . ($facture['societe'] ? $facture['societe'] : $facture['nom'] . '_' . $facture['prenom']) . ' - ' . date('Y-m-d_H-i', $facture['date_facture']) . '.pdf', 'D');
        } else {
            $pdf->Output($chemin, 'F');
        }

        return $reference;
    }

    /**
     * Envoi par mail d'une facture au format PDF
     *
     * @param string|array $reference Invoicing reference as string, or the invoice itself
     * @access public
     * @return bool Succès de l'envoi
     */
    function envoyerFacture($reference, $copyTresorier = true, $facturer = true)
    {
        $configuration = $GLOBALS['AFUP_CONF'];

        if (is_array($reference)) {
            $personne = $reference;
            $reference = $personne['reference'];
        } else {
            $personne = $this->obtenir($reference, 'email, nom, prenom');
        }

        $cheminFacture = AFUP_CHEMIN_RACINE . 'cache' . DIRECTORY_SEPARATOR . 'fact' . $reference . '.pdf';
        $numeroFacture = $this->genererFacture($reference, $cheminFacture);

        $message = new Message(
            'Facture évènement AFUP',
            MailUserFactory::afup(),
            new MailUser($personne['email'], sprintf('%s %s', $personne['prenom'], $personne['nom']))
        );
        $mailer = Mail::createMailer();
        $mailer->renderTemplate($message,'mail_templates/facture-forum.html.twig', [
            'raison_sociale' => $configuration->obtenir('afup|raison_sociale'),
            'adresse' => $configuration->obtenir('afup|adresse'),
            'ville' => $configuration->obtenir('afup|code_postal').' '.$configuration->obtenir('afup|ville'),
        ]);
        $message->addAttachment(new Attachment(
            $cheminFacture,
            'facture-'.$numeroFacture.'.pdf',
            'base64',
            'application/pdf'
        ));
        if ($copyTresorier) {
            $message->addBcc(MailUserFactory::tresorier());
        }
        $ok = $mailer->send($message);
        @unlink($cheminFacture);

        if ($ok && $facturer) {
            $this->estFacture($reference);
        }

        return $ok;
    }

    /**
     * Changement de la date de réglement d'une facture
     * @param integer $reference
     * @param integer $date_reglement
     */
    public function changerDateReglement($reference, $date_reglement)
    {
        $requete = 'UPDATE ';
        $requete .= '  afup_facturation_forum ';
        $requete .= 'SET ';
        $requete .= '  date_reglement = ' . intval($date_reglement) . ' ';
        $requete .= 'WHERE';
        $requete .= '  reference=' . $this->_bdd->echapper($reference);
        return $this->_bdd->executer($requete);
    }
}
