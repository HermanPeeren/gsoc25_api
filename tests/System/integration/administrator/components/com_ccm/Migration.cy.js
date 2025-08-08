describe('CCM Migration Controller', () => {
  beforeEach(() => {
    cy.doAdministratorLogin();
    cy.visit('/administrator/index.php?option=com_ccm&view=migration');
    cy.get('body').should('be.visible');
  });

  afterEach(() => {
    cy.task('queryDB', "DELETE FROM #__content");
    cy.task('queryDB', "DELETE FROM #__categories WHERE id > 2");
    cy.task('queryDB', "DELETE FROM #__tags");
  })

  it('has the correct page title', () => {
    cy.get('h1').should('contain.text', 'Content Migration');
  });

  it('has migration settings card', () => {
    cy.get('.card-title').should('contain.text', 'Migration Settings');
  });

  it('can display a list of source CMS', () => {
    cy.get('[name="jform[source_cms]"]').should('exist');
    cy.get('[name="jform[source_cms]"] option').should('have.length.greaterThan', 0);
    cy.get('[name="jform[source_cms]"] option').each(($option) => {
      expect($option.text()).to.not.be.empty;
    });
  });

  it('shows the Apply Migration button', () => {
    cy.get('button[type="submit"]').should('contain.text', 'Apply Migration');
    cy.get('button[type="submit"]').should('have.class', 'btn-primary');
    cy.get('button[type="submit"]').should('have.class', 'btn-lg');
  });

  it('shows error when no data is provided', () => {
    cy.get('button[type="submit"]').contains("Apply Migration").click();
    cy.get('[name="jform[source_cms]"]').blur();
    cy.get('[name="jform[source_cms]"]')
      .should('have.class', 'invalid')
      .and('have.class', 'form-control-danger')
      .and('have.attr', 'aria-invalid', 'true');
  });

  it('shows migration successful for complete migration', () => {
    cy.get('[name="jform[source_cms]"]').select('WordPress');
    cy.get('[name="jform[target_cms]"]').select('Joomla');
    cy.get('button[type="submit"]').contains("Apply Migration").click();
    cy.checkForSystemMessage("All migrations completed successfully");
  });

  // it('shows error due to duplication', () => {
  //   cy.get('[name="jform[source_cms]"]').select('WordPress');
  //   cy.get('[name="jform[target_cms]"]').select('Joomla');
  //   cy.get('button[type="submit"]').contains("Apply Migration").click();
  //   // cy.get('#migration-message', { timeout: 10000 }).should('contain.text', 'Migration failed');
  //   cy.checkForSystemMessage("Partial migration completed");
  // });
});