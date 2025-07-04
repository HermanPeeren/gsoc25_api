describe('CCM Migration Controller', () => {
  beforeEach(() => {
    cy.doAdministratorLogin();
    cy.visit('/administrator/index.php?option=com_ccm&view=migration');
  });

  afterEach(() => {
    cy.task('queryDB', "DELETE FROM #__content");
    cy.task('queryDB', "DELETE FROM #__categories WHERE id > 2");
  })

  it('has a title', () => {
    cy.get('legend').should('contain.text', 'Migration Settings');
  });

  it('can display a list of source CMS', () => {
    cy.get('[name="jform[source_cms]"]').should('exist');
    cy.get('[name="jform[source_cms]"] option').should('have.length.greaterThan', 0);
    cy.get('[name="jform[source_cms]"] option').each(($option) => {
      expect($option.text()).to.not.be.empty;
    });
  });

  it('shows error when no data is provided', () => {
    cy.get('button[type="submit"]').contains("Apply Migration").click();
    cy.get('[name="jform[source_cms]"]').blur();
    cy.get('[name="jform[source_cms]"]')
      .should('have.class', 'invalid')
      .and('have.class', 'form-control-danger')
      .and('have.attr', 'aria-invalid', 'true');
  });

  it('shows migration successful for categories', () => {
    cy.get('[name="jform[source_cms]"]').select('WordPress');
    cy.get('[name="jform[source_cms_object_type]"]').select('Categories');
    cy.get('[name="jform[target_cms]"]').select('Joomla');
    cy.get('[name="jform[target_cms_object_type]"]').select('Categories');
    cy.get('button[type="submit"]').contains("Apply Migration").click();
    cy.checkForSystemMessage("Migration applied successfully");
  });

  it('shows migration successful for articles', () => {
    cy.get('[name="jform[source_cms]"]').select('WordPress');
    cy.get('[name="jform[source_cms_object_type]"]').select('Posts');
    cy.get('[name="jform[target_cms]"]').select('Joomla');
    cy.get('[name="jform[target_cms_object_type]"]').select('Articles');
    cy.get('button[type="submit"]').contains("Apply Migration").click();
    cy.checkForSystemMessage("Migration applied successfully");
  });

  it('shows error due to duplication', () => {
    cy.get('[name="jform[source_cms]"]').select('WordPress');
    cy.get('[name="jform[source_cms_object_type]"]').select('Posts');
    cy.get('[name="jform[target_cms]"]').select('Joomla');
    cy.get('[name="jform[target_cms_object_type]"]').select('Articles');
    cy.get('button[type="submit"]').contains("Apply Migration").click();
    cy.checkForSystemMessage("Migration failed");
  });
});